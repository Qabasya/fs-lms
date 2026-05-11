<?php

declare(strict_types=1);

namespace Inc\Controllers\Builders;

use Inc\DTO\PostViewDTO;
use Inc\DTO\SubjectDTO;
use Inc\DTO\TermViewDTO;
use Inc\Enums\PostMetaName;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Services\ArticleService;
use Inc\Services\PostTypeResolver;
use Inc\Services\TaskMetaService;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;

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
class TaskDataBuilder {

	/**
	 * @param SubjectRepository  $subject_repository  Репозиторий предметов.
	 * @param TaxonomyRepository $taxonomy_repository Репозиторий таксономий.
	 * @param TaskMetaService    $task_meta_service   Сервис мета-данных задания.
	 * @param PostManager        $post_manager        Менеджер записей WordPress.
	 * @param ArticleService     $article_service     Сервис статей предмета.
	 * @param TermManager        $term_manager        Менеджер терминов таксономии.
	 */
	public function __construct(
		private readonly SubjectRepository $subject_repository,
		private readonly TaxonomyRepository $taxonomy_repository,
		private readonly TaskMetaService $task_meta_service,
		private readonly PostManager $post_manager,
		private readonly ArticleService $article_service,
		private readonly TermManager $term_manager,
	) {}

	/**
	 * Возвращает полный массив данных задания для frontend-шаблона.
	 *
	 * @param int $post_id ID записи задания.
	 *
	 * @return array Массив данных страницы задания.
	 */
	public function getTaskData( int $post_id ): array {
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
	 * Собирает единую структуру данных страницы задания.
	 *
	 * @param PostViewDTO|null  $post              DTO записи задания.
	 * @param string            $subject_key       Ключ предмета.
	 * @param array             $meta              Мета-данные задания.
	 * @param SubjectDTO|null   $subject           DTO предмета.
	 * @param TermViewDTO|null  $current_task_type DTO текущего типа задания.
	 *
	 * @return array Массив данных страницы задания.
	 */
	private function buildTaskData(
		?PostViewDTO $post = null,
		string $subject_key = '',
		array $meta = array(),
		?SubjectDTO $subject = null,
		?TermViewDTO $current_task_type = null
	): array {
		$post_id       = $post?->id ?? 0;
		$subject_label = $subject ? $subject->name : $subject_key;

		return array(
			'post'       => $this->buildPostData( $post ),
			'subject'    => $this->buildSubjectData( $subject_key, $subject_label ),
			'content'    => $this->buildContentData( $meta ),
			'files'      => $this->task_meta_service->getTaskFiles( $meta ),
			'tags'       => $this->buildTags( $post_id, $subject_key, $current_task_type ),
			'articles'   => $this->buildArticles( $subject_key, $current_task_type ),
			'navigation' => $this->buildNavigation( $post, $subject_label, $current_task_type, $subject_key ),
		);
	}

	/**
	 * Возвращает данные записи для шаблона из DTO.
	 *
	 * @param PostViewDTO|null $post DTO записи задания.
	 *
	 * @return array
	 */
	private function buildPostData( ?PostViewDTO $post ): array {
		return array(
			'id'        => $post?->id ?? 0,
			'title'     => $post?->title ?? '',
			'slug'      => $post?->slug ?? '',
			'post_type' => $post?->post_type ?? '',
			'url'       => $post?->url ?? '',
		);
	}

	/**
	 * Возвращает данные предмета для шаблона.
	 *
	 * @param string $subject_key   Ключ предмета.
	 * @param string $subject_label Название предмета.
	 *
	 * @return array
	 */
	private function buildSubjectData( string $subject_key, string $subject_label ): array {
		return array(
			'key'  => $subject_key,
			'name' => $subject_label,
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
		return array(
			'condition' => $this->task_meta_service->getCombinedCondition( $meta ),
			'answer'    => $meta['task_answer'] ?? '',
			'code'      => $meta['task_code'] ?? '',
			'text'      => $meta['task_text'] ?? '',
		);
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
				'url'      => '',
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
					'url'           => '',
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
	 * @param string           $subject_label     Название предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 * @param string           $subject_key       Ключ предмета.
	 *
	 * @return array
	 */
	private function buildNavigation(
		?PostViewDTO $post,
		string $subject_label,
		?TermViewDTO $current_task_type,
		string $subject_key = ''
	): array {
		$post_id     = $post?->id ?? 0;
		$archive_url = $subject_key ? ( get_post_type_archive_link( $subject_key . '_tasks' ) ?: '' ) : '';
		$term_url    = '';

		if ( $current_task_type ) {
			$link     = get_term_link( $current_task_type->id, $current_task_type->taxonomy );
			$term_url = is_wp_error( $link ) ? '' : $link;
		}

		$prev_post = $post_id ? $this->post_manager->getAdjacent( $post_id, true ) : null;
		$next_post = $post_id ? $this->post_manager->getAdjacent( $post_id, false ) : null;

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
					'label' => $post ? '№ ' . rawurldecode( $post->slug ) : '',
					'url'   => $post?->url ?? '',
				),
			),
			'prev'        => $prev_post ? array(
				'title' => $prev_post->post_title,
				'url'   => get_permalink( $prev_post->ID ),
				'slug'  => rawurldecode( $prev_post->post_name ),
			) : null,
			'next'        => $next_post ? array(
				'title' => $next_post->post_title,
				'url'   => get_permalink( $next_post->ID ),
				'slug'  => rawurldecode( $next_post->post_name ),
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
	 * Возвращает пустую структуру данных страницы задания.
	 *
	 * Используется, если запись не найдена или не является заданием.
	 *
	 * @return array
	 */
	private function emptyTaskData(): array {
		return $this->buildTaskData();
	}
}