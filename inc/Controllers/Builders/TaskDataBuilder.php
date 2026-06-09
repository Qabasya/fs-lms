<?php

declare(strict_types=1);

namespace Inc\Controllers\Builders;

use Inc\DTO\Task\PostViewDTO;
use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Task\TaskPageDTO;
use Inc\DTO\Subject\TermViewDTO;
use Inc\Enums\PostMetaName;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\ArticleService;
use Inc\Services\PostTypeResolver;
use Inc\Services\Task\TaskMetaService;

/**
 * Class TaskDataBuilder
 *
 * Строитель данных frontend-страницы задания.
 *
 * Собирает полный массив данных для шаблона: запись, предмет, контент,
 * файлы, теги, статьи и навигацию. WP_Post и WP_Term нормализуются в DTO
 * на входе, после чего сборка работает только с типизированными объектами.
 *
 * @package Inc\Controllers\Builders
 */
readonly class TaskDataBuilder {

	private const KEY_ANSWER = 'answer';
	private const KEY_CODE   = 'code';
	private const KEY_TEXT   = 'text';

	/**
	 * @param SubjectRepository  $subject_repository  Репозиторий предметов.
	 * @param TaxonomyRepository $taxonomy_repository Репозиторий таксономий.
	 * @param TaskMetaService    $task_meta_service   Сервис мета-данных задания.
	 * @param PostManager        $post_manager        Менеджер записей WordPress.
	 * @param ArticleService     $article_service     Сервис статей предмета.
	 * @param TermManager        $term_manager        Менеджер терминов таксономии.
	 */
	public function __construct(
		private SubjectRepository $subject_repository,
		private TaxonomyRepository $taxonomy_repository,
		private TaskMetaService $task_meta_service,
		private PostManager $post_manager,
		private ArticleService $article_service,
		private TermManager $term_manager,
	) {}

	/**
	 * Возвращает данные задания для frontend-шаблона.
	 *
	 * @param int $post_id ID записи задания.
	 *
	 * @return TaskPageDTO
	 */
	public function getTaskData( int $post_id ): TaskPageDTO {
		$post = $this->post_manager->get( $post_id );

		if ( ! $post || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return $this->emptyTaskData();
		}

		$subject_key       = PostTypeResolver::subjectFromTaskPostType( $post->post_type );
		$meta              = $this->post_manager->getMeta( $post_id, PostMetaName::Meta->value );
		$meta              = is_array( $meta ) ? $meta : array();
		$subject           = $this->subject_repository->getByKey( $subject_key );
		$post_view         = PostViewDTO::normalizePost( $post );
		$current_task_type = $this->getCurrentTaskType( $post_id, $subject_key );

		return $this->buildTaskData( $post_view, $subject_key, $meta, $subject, $current_task_type );
	}

	/**
	 * Собирает DTO данных страницы задания.
	 *
	 * @param PostViewDTO|null $post              DTO записи задания.
	 * @param string           $subject_key       Ключ предмета.
	 * @param array            $meta              Мета-данные задания.
	 * @param SubjectDTO|null  $subject           DTO предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return TaskPageDTO
	 */
	private function buildTaskData(
		?PostViewDTO $post = null,
		string $subject_key = '',
		array $meta = array(),
		?SubjectDTO $subject = null,
		?TermViewDTO $current_task_type = null
	): TaskPageDTO {
		$subject_name = $subject ? $subject->name : $subject_key;
		$content      = $this->buildContentData( $meta );

		return new TaskPageDTO(
			post:         $post,
			subject_key:  $subject_key,
			subject_name: $subject_name,
			content:      $content,
			files:        $this->task_meta_service->getTaskFiles( $meta ),
			tags:         $this->buildTags( $post?->id ?? 0, $subject_key, $current_task_type ),
			articles:     $this->buildArticles( $subject_key, $current_task_type ),
			navigation:   $this->buildNavigation( $post, $subject_key, $subject_name, $current_task_type ),
			tabs:         $this->buildTabs( $content ),
		);
	}

	/**
	 * Возвращает основное содержимое задания из мета-данных.
	 *
	 * @param array $meta Мета-данные задания.
	 *
	 * @return array
	 */
	private function buildContentData( array $meta ): array {
		$raw_code = $meta['task_code'] ?? '';

		return array(
			'condition'       => $this->task_meta_service->getCombinedCondition( $meta ),
			self::KEY_ANSWER  => $meta['task_answer'] ?? '',
			self::KEY_CODE    => '' !== $raw_code ? '<pre><code>' . esc_html( $raw_code ) . '</code></pre>' : '',
			self::KEY_TEXT    => $meta['task_text'] ?? '',
		);
	}

	/**
	 * Возвращает список табов для шаблона на основе готового массива content.
	 *
	 * @param array $content Массив контента задания из buildContentData().
	 *
	 * @return array Список табов.
	 */
	private function buildTabs( array $content ): array {
		$tabs = array();

		if ( ! empty( $content[ self::KEY_ANSWER ] ) ) {
			$tabs[] = array( 'id' => self::KEY_ANSWER, 'label' => 'Ответ', 'content' => $content[ self::KEY_ANSWER ] );
		}
		if ( ! empty( $content[ self::KEY_CODE ] ) ) {
			$tabs[] = array( 'id' => self::KEY_CODE, 'label' => 'Решение', 'content' => $content[ self::KEY_CODE ] );
		}
		if ( ! empty( $content[ self::KEY_TEXT ] ) ) {
			$tabs[] = array( 'id' => self::KEY_TEXT, 'label' => 'Пояснение', 'content' => $content[ self::KEY_TEXT ] );
		}

		return $tabs;
	}

	/**
	 * Возвращает теги и метрики задания.
	 *
	 * Включает тег типа задания и все термины пользовательских таксономий предмета.
	 * Термины из WP_Term нормализуются в TermViewDTO внутри метода.
	 *
	 * @param int              $post_id           ID записи.
	 * @param string           $subject_key       Ключ предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return array Список тегов задания.
	 */
	private function buildTags( int $post_id, string $subject_key, ?TermViewDTO $current_task_type ): array {
		$tags = array();

		if ( $current_task_type ) {
			$tags[] = array(
				'type'     => 'task_type',
				'label'    => 'Задание №' . $current_task_type->name,
				'taxonomy' => $current_task_type->taxonomy,
				'term_id'  => $current_task_type->id,
				'slug'     => $current_task_type->slug,
				'url'      => $this->term_manager->getLink( $current_task_type->id, $current_task_type->taxonomy ),
			);
		}

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $taxonomy_dto ) {
			$raw_terms = $this->term_manager->getPostTerms( $post_id, $taxonomy_dto->slug );

			foreach ( $raw_terms as $raw_term ) {
				$term = TermViewDTO::normalizeTerm( $raw_term );

				if ( ! $term ) {
					continue;
				}

				$tags[] = array(
					'type'          => 'taxonomy',
					'taxonomy'      => $taxonomy_dto->slug,
					'taxonomy_name' => $taxonomy_dto->name,
					'label'         => $term->name,
					'term_id'       => $term->id,
					'slug'          => $term->slug,
					'url'           => $this->term_manager->getLink( $term->id, $taxonomy_dto->slug ),
				);
			}
		}

		return $tags;
	}

	/**
	 * Возвращает статьи для страницы задания.
	 *
	 * @param string           $subject_key       Ключ предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return array Массив с ключами 'related' и 'random'.
	 */
	private function buildArticles( string $subject_key, ?TermViewDTO $current_task_type ): array {
		return array(
			'related' => $this->article_service->getRelatedArticles( $subject_key, $current_task_type ),
			'random'  => $this->article_service->getRandomArticles( $subject_key ),
		);
	}

	/**
	 * Возвращает данные навигации, хлебных крошек и соседних постов.
	 *
	 * @param PostViewDTO|null $post              DTO записи задания.
	 * @param string           $subject_key       Ключ предмета.
	 * @param string           $subject_label     Название предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return array
	 */
	private function buildNavigation(
		?PostViewDTO $post,
		string $subject_key,
		string $subject_label,
		?TermViewDTO $current_task_type,
	): array {
		$post_id     = $post?->id ?? 0;
		$archive_url = $subject_key ? $this->post_manager->getArchiveLink( PostTypeResolver::tasks( $subject_key ) ) : '';
		$term_url    = $current_task_type ? $this->term_manager->getLink( $current_task_type->id, $current_task_type->taxonomy ) : '';

		$prev_post = $post_id ? PostViewDTO::normalizePost( $this->post_manager->getAdjacent( $post_id, true ) ) : null;
		$next_post = $post_id ? PostViewDTO::normalizePost( $this->post_manager->getAdjacent( $post_id, false ) ) : null;

		return array(
			'breadcrumbs' => array(
				'subject'   => array(
					'label' => $subject_label,
					'url'   => $archive_url,
				),
				'trainer'   => array(
					'label' => 'Тренажер',
					'url'   => $archive_url,
				),
				'task_type' => $current_task_type ? array(
					'id'    => $current_task_type->id,
					'label' => $current_task_type->name . ' задание',
					'slug'  => $current_task_type->slug,
					'url'   => $term_url,
				) : null,
				'task'      => array(
					'label' => $post ? $post->title : '',
					'url'   => $post?->url ?? '',
				),
			),
			'prev'        => $prev_post ? array(
				'title' => $prev_post->title,
				'url'   => $prev_post->url,
				'slug'  => rawurldecode( $prev_post->slug ),
			) : null,
			'next'        => $next_post ? array(
				'title' => $next_post->title,
				'url'   => $next_post->url,
				'slug'  => rawurldecode( $next_post->slug ),
			) : null,
		);
	}

	/**
	 * Возвращает DTO типа задания, привязанного к записи.
	 *
	 * @param int    $post_id     ID записи.
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return TermViewDTO|null
	 */
	private function getCurrentTaskType( int $post_id, string $subject_key ): ?TermViewDTO {
		$terms = $this->term_manager->getPostTerms(
			$post_id,
			PostTypeResolver::getTaskTaxonomy( $subject_key )
		);

		return TermViewDTO::normalizeTerm( $terms[0] ?? null );
	}

	/**
	 * Возвращает пустой TaskPageDTO.
	 *
	 * Используется, если запись не найдена или не является заданием.
	 *
	 * @return TaskPageDTO
	 */
	private function emptyTaskData(): TaskPageDTO {
		return $this->buildTaskData();
	}
}
