<?php

declare(strict_types=1);

namespace Inc\Controllers\Builders;

use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Subject\SubjectLinksDTO;
use Inc\DTO\Subject\TermViewDTO;
use Inc\DTO\Task\AdjacentTaskDTO;
use Inc\DTO\Task\NavigationDTO;
use Inc\DTO\Task\PostViewDTO;
use Inc\DTO\Task\TabDTO;
use Inc\DTO\Task\TagDTO;
use Inc\DTO\Task\TaskContentDTO;
use Inc\DTO\Task\TaskPageDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Course\PublicCourseService;
use Inc\Services\Subject\ArticleService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectPagesService;
use Inc\Services\Subject\TagPaletteService;
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

	private const KEY_CODE = 'code';
	private const KEY_TEXT = 'text';

	/** Сколько случайных статей показать, если по типу задания их нет. */
	private const SIDEBAR_ARTICLES = 3;

	/** Язык листинга: поля выбора языка у задания нет, все листинги — Python. */
	private const CODE_LANG = 'Python';

	/**
	 * @param SubjectRepository  $subject_repository  Репозиторий предметов.
	 * @param TaxonomyRepository $taxonomy_repository Репозиторий таксономий.
	 * @param TaskMetaService    $task_meta_service   Сервис мета-данных задания.
	 * @param PostManager        $post_manager        Менеджер записей WordPress.
	 * @param ArticleService     $article_service     Сервис статей предмета.
	 * @param TermManager         $term_manager        Менеджер терминов таксономии.
	 * @param BreadcrumbsBuilder  $breadcrumbs_builder Строитель хлебных крошек.
	 * @param PublicCourseService $course_service      Курсы предмета для сайдбара.
	 * @param TagPaletteService   $tag_palette         Цвет чипа по таксономии.
	 */
	public function __construct(
		private SubjectRepository $subject_repository,
		private TaxonomyRepository $taxonomy_repository,
		private TaskMetaService $task_meta_service,
		private PostManager $post_manager,
		private ArticleService $article_service,
		private TermManager $term_manager,
		private BreadcrumbsBuilder $breadcrumbs_builder,
		private PublicCourseService $course_service,
		private TagPaletteService $tag_palette,
		private SubjectPagesService $subject_pages,
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
			return TaskPageDTO::empty();
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
	 * @param PostViewDTO      $post              DTO записи задания.
	 * @param string           $subject_key       Ключ предмета.
	 * @param array            $meta              Мета-данные задания.
	 * @param SubjectDTO|null  $subject           DTO предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return TaskPageDTO
	 */
	private function buildTaskData(
		PostViewDTO $post,
		string $subject_key,
		array $meta,
		?SubjectDTO $subject,
		?TermViewDTO $current_task_type
	): TaskPageDTO {
		$subject_name = $subject ? $subject->name : $subject_key;
		$content      = $this->buildContentData( $meta );
		$links        = $this->subject_pages->links( $subject_key );

		return new TaskPageDTO(
			post:         $post,
			subject_key:  $subject_key,
			subject_name: $subject_name,
			content:      $content,
			files:        $this->task_meta_service->getTaskFiles( $meta ),
			tags:         $this->buildTags( $post->id, $subject_key, $current_task_type, $links->trainer ),
			articles:     $this->buildArticles( $subject_key, $current_task_type, $links->textbook ),
			courses:      $this->course_service->getSidebarCourses( $subject_key ),
			courses_url:  $links->courses,
			navigation:   $this->buildNavigation( $post, $subject_name, $links, $current_task_type ),
			tabs:         $this->buildTabs( $content ),
		);
	}

	/**
	 * Возвращает основное содержимое задания из мета-данных.
	 *
	 * Листинг решения отдаётся сырым: разметку `<pre><code class="js-code">`
	 * печатает шаблон, редактор с подсветкой собирает `code-block.js`.
	 *
	 * @param array $meta Мета-данные задания.
	 *
	 * @return TaskContentDTO
	 */
	private function buildContentData( array $meta ): TaskContentDTO {
		$code = (string) ( $meta['task_code'] ?? '' );

		return new TaskContentDTO(
			condition: $this->task_meta_service->getCombinedCondition( $meta ),
			answer:    (string) ( $meta['task_answer'] ?? '' ),
			code:      $code,
			code_lang: '' !== $code ? self::CODE_LANG : '',
			text:      (string) ( $meta['task_text'] ?? '' ),
		);
	}

	/**
	 * Возвращает список табов для шаблона на основе готового контента.
	 *
	 * Ответа среди табов нет: он выводится тем же блоком, что и в карточке
	 * списка на «Всех заданиях» — кнопка в футере карточки плюс панель
	 * (`$content->answer` шаблон берёт напрямую).
	 *
	 * @param TaskContentDTO $content Контент задания из buildContentData().
	 *
	 * @return TabDTO[]
	 */
	private function buildTabs( TaskContentDTO $content ): array {
		$tabs = array();

		if ( '' !== $content->code ) {
			$tabs[] = new TabDTO(
				id:      self::KEY_CODE,
				label:   'Решение',
				content: $content->code,
				is_code: true,
				lang:    $content->code_lang,
			);
		}
		if ( '' !== $content->text ) {
			$tabs[] = new TabDTO( id: self::KEY_TEXT, label: 'Пояснение', content: $content->text );
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
	 * @param string           $trainer_url       Ссылка на тренажёр предмета.
	 *
	 * @return TagDTO[]
	 */
	private function buildTags( int $post_id, string $subject_key, ?TermViewDTO $current_task_type, string $trainer_url ): array {
		$tags = array();

		if ( $current_task_type ) {
			$tags[] = new TagDTO(
				type:          TagDTO::TYPE_TASK_TYPE,
				label:         'Задание №' . $current_task_type->name,
				taxonomy:      $current_task_type->taxonomy,
				taxonomy_name: '',
				term_id:       $current_task_type->id,
				slug:          $current_task_type->slug,
				url:           $this->filterUrl( $trainer_url,$current_task_type->taxonomy, $current_task_type->slug ),
				color:         $this->tag_palette->colorIndex( $subject_key, $current_task_type->taxonomy ),
			);
		}

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $taxonomy_dto ) {
			// Только обязательные таксономии: их термины есть у каждого задания и по
			// ним фильтруются «Все задания» — тег ведёт именно туда. Необязательные
			// (служебные пометки автора предмета) посетителю не показываем.
			if ( ! $taxonomy_dto->is_required ) {
				continue;
			}

			$raw_terms = $this->term_manager->getPostTerms( $post_id, $taxonomy_dto->slug );

			foreach ( $raw_terms as $raw_term ) {
				$term = TermViewDTO::normalizeTerm( $raw_term );

				if ( ! $term ) {
					continue;
				}

				$tags[] = new TagDTO(
					type:          TagDTO::TYPE_TAXONOMY,
					label:         $term->name,
					taxonomy:      $taxonomy_dto->slug,
					taxonomy_name: $taxonomy_dto->name,
					term_id:       $term->id,
					slug:          $term->slug,
					url:           $this->filterUrl( $trainer_url,$taxonomy_dto->slug, $term->slug ),
					color:         $this->tag_palette->colorIndex( $subject_key, $taxonomy_dto->slug ),
				);
			}
		}

		return $tags;
	}

	/**
	 * Ссылка на тренажёр с предвыбранным фильтром.
	 *
	 * Формат параметра тот же, что у AJAX-подгрузки списка:
	 * `filters[<taxonomy_slug>][]=<term_slug>` — страница разбирает его на SSR
	 * и отмечает опцию в сайдбаре как активную.
	 *
	 * @param string $trainer_url Ссылка на тренажёр предмета.
	 * @param string $taxonomy    Слаг таксономии.
	 * @param string $term_slug   Слаг термина.
	 *
	 * @return string Пустая строка, если раздел или термин неизвестны.
	 */
	private function filterUrl( string $trainer_url, string $taxonomy, string $term_slug ): string {
		if ( '' === $trainer_url || '' === $taxonomy || '' === $term_slug ) {
			return '';
		}

		return add_query_arg( array( 'filters' => array( $taxonomy => array( $term_slug ) ) ), $trainer_url );
	}

	/**
	 * Возвращает статьи для страницы задания.
	 *
	 * @param string           $subject_key       Ключ предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 * @param string           $textbook_url      Учебник предмета — ссылка «Все материалы».
	 *
	 * @return array Ключи: 'related' (по типу задания, иначе случайные),
	 *               'recommended' (свежие),
	 *               'archive_url' (ссылка «Все материалы»).
	 */
	private function buildArticles( string $subject_key, ?TermViewDTO $current_task_type, string $textbook_url ): array {
		$related = $this->article_service->getRelatedArticles( $subject_key, $current_task_type );

		// По типу задания статей нет — показываем случайные статьи предмета,
		// иначе блок сайдбара пустовал бы (тот же приём, что на «Всех заданиях»).
		if ( empty( $related ) ) {
			$related = $this->article_service->getSidebarArticles( $subject_key, array(), self::SIDEBAR_ARTICLES );
		}

		return array(
			'related'     => $related,
			'recommended' => $this->article_service->getLatestArticles( $subject_key ),
			'archive_url' => $textbook_url,
		);
	}

	/**
	 * Возвращает данные навигации, хлебных крошек и соседних постов.
	 *
	 * Навигация ходит только по заданиям ТОГО ЖЕ типа (термин {key}_task_number)
	 * и закольцована: с последнего задания «Следующее» ведёт на первое, а с
	 * первого «Предыдущее» — на последнее.
	 *
	 * @param PostViewDTO      $post              DTO записи задания.
	 * @param string           $subject_label     Название предмета.
	 * @param SubjectLinksDTO  $links             Ссылки на разделы предмета.
	 * @param TermViewDTO|null $current_task_type DTO текущего типа задания.
	 *
	 * @return NavigationDTO
	 */
	private function buildNavigation(
		PostViewDTO $post,
		string $subject_label,
		SubjectLinksDTO $links,
		?TermViewDTO $current_task_type,
	): NavigationDTO {
		$term_url = $current_task_type
			? $this->filterUrl( $links->trainer, $current_task_type->taxonomy, $current_task_type->slug )
			: '';

		$taxonomy = $current_task_type?->taxonomy ?? '';

		$prev = $this->post_manager->getAdjacent( $post->id, true, $taxonomy )
			?? $this->edgeTask( $post, $current_task_type, false );
		$next = $this->post_manager->getAdjacent( $post->id, false, $taxonomy )
			?? $this->edgeTask( $post, $current_task_type, true );

		$prev_post = PostViewDTO::normalizePost( $prev );
		$next_post = PostViewDTO::normalizePost( $next );

		return new NavigationDTO(
			// Общая с «Все задания» цепочка: плоский список для партиала крошек.
			breadcrumbs: $this->breadcrumbs_builder->forTask(
				$subject_label,
				$links,
				$current_task_type ? $current_task_type->name . ' задание' : '',
				$term_url,
				$post->title
			),
			archive_url: $links->trainer,
			prev:        $this->adjacentLink( $prev_post ),
			next:        $this->adjacentLink( $next_post ),
		);
	}

	/**
	 * Крайнее задание того же типа — точка «перескока» кольцевой навигации.
	 *
	 * @param PostViewDTO      $post      Текущее задание.
	 * @param TermViewDTO|null $task_type Тип задания; null — кольцо по всем заданиям предмета.
	 * @param bool             $oldest    true — самое старое (для «Следующего» с конца),
	 *                                    false — самое свежее (для «Предыдущего» с начала).
	 *
	 * @return \WP_Post|null Null, если задание этого типа единственное.
	 */
	private function edgeTask( PostViewDTO $post, ?TermViewDTO $task_type, bool $oldest ): ?\WP_Post {
		$opts = array(
			'status'  => 'publish',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => $oldest ? 'ASC' : 'DESC',
		);

		if ( $task_type ) {
			$opts['tax_query'] = array(
				array(
					'taxonomy' => $task_type->taxonomy,
					'field'    => 'term_id',
					'terms'    => array( $task_type->id ),
				),
			);
		}

		$found = $this->post_manager->search( $post->post_type, $opts )[0] ?? null;

		// Единственное задание типа: кольцо вырождается — соседа нет.
		return ( $found instanceof \WP_Post && $found->ID !== $post->id ) ? $found : null;
	}

	/**
	 * Ссылка на соседнее задание для блока навигации.
	 *
	 * @param PostViewDTO|null $post DTO соседней записи.
	 *
	 * @return AdjacentTaskDTO|null
	 */
	private function adjacentLink( ?PostViewDTO $post ): ?AdjacentTaskDTO {
		if ( ! $post ) {
			return null;
		}

		return new AdjacentTaskDTO(
			title: $post->title,
			url:   $post->url,
			slug:  rawurldecode( $post->slug ),
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
}
