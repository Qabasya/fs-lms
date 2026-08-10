<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\DTO\Task\AllTasksPageDTO;
use Inc\DTO\Task\TaskListItemDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Course\PublicCourseService;
use Inc\Services\Subject\ArticleService;
use Inc\Services\Subject\FilterGroupService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectPagesService;
use Inc\Services\Subject\TagPaletteService;
use Inc\Services\Task\TaskMetaService;

/**
 * Class AllTasksDataBuilder
 *
 * Строитель данных страницы «Все задания».
 *
 * Собирает данные для первичного рендера (SSR) и для AJAX-дозагрузки:
 * группы фильтров (таксономии предмета: тип задания + пользовательские),
 * постраничный список карточек заданий (сортировка — по дате, сначала новые).
 *
 * Классификаторы (год, источник/автор и т.п.) — это термины таксономий предмета,
 * проставленные при создании задания; фильтрация идёт через tax_query.
 *
 * @package Inc\Controllers\Builders
 */
readonly class AllTasksDataBuilder {

	public const PER_PAGE = 10;

	/** Сколько статей показывает сайдбар страницы (SSR и AJAX — одно число). */
	private const SIDEBAR_ARTICLES = 2;

	public function __construct(
		private SubjectRepository  $subject_repository,
		private TaxonomyRepository $taxonomy_repository,
		private PostManager        $post_manager,
		private TermManager        $term_manager,
		private TaskMetaService    $task_meta_service,
		private BreadcrumbsBuilder $breadcrumbs_builder,
		private ArticleService     $article_service,
		private PublicCourseService $course_service,
		private TagPaletteService  $tag_palette,
		private SubjectPagesService $subject_pages,
		private FilterGroupService $filter_groups,
	) {}

	/**
	 * Полный DTO страницы для первичного рендера.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param array  $selected    Предвыбранные фильтры из URL: [taxonomy => term_slugs].
	 *
	 * @return AllTasksPageDTO
	 */
	public function getPageData( string $subject_key, array $selected = array() ): AllTasksPageDTO {
		$subject      = $this->subject_repository->getByKey( $subject_key );
		$subject_name = $subject?->name ?? $subject_key;

		$filters = array( 'taxonomies' => $selected );
		$links   = $this->subject_pages->links( $subject_key );

		[ $tasks, $total ] = $this->fetchTasks( $subject_key, $filters, 0, self::PER_PAGE );

		return new AllTasksPageDTO(
			subject_key:  $subject_key,
			subject_name: $subject_name,
			breadcrumbs:  $this->breadcrumbs_builder->forArchive( $subject_name, $links ),
			filters:      $this->buildFilters( $subject_key, $filters ),
			articles:     $this->fetchArticles( $subject_key, $selected ),
			articles_url: $links->articles,
			courses:      $this->course_service->getSidebarCourses( $subject_key ),
			courses_url:  $links->courses,
			tasks:        $tasks,
			total:        $total,
			per_page:     self::PER_PAGE,
			has_more:     count( $tasks ) < $total,
			nonce:        Nonce::AllTasks->create(),
		);
	}

	/**
	 * Статьи сайдбара под текущий выбор типов задания.
	 *
	 * Реагирует только на фиксированную таксономию номеров: остальные фильтры
	 * (год, автор) на подбор статей не влияют.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param array  $selected    Активные фильтры: [taxonomy => term_slugs].
	 *
	 * @return array Список статей для шаблона.
	 */
	public function fetchArticles( string $subject_key, array $selected ): array {
		$number_tax = PostTypeResolver::getTaskTaxonomy( $subject_key );

		return $this->article_service->getSidebarArticles(
			$subject_key,
			$selected[ $number_tax ] ?? array(),
			self::SIDEBAR_ARTICLES
		);
	}

	/**
	 * Отфильтрованный и постраничный список заданий (сортировка — дата DESC).
	 *
	 * $filters:
	 *   - search     (string)                поиск по заголовку/условию (post_content-зеркало)
	 *   - taxonomies (array<string,string[]>) [tax_slug => term_slugs] — фильтр по терминам
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param array  $filters     Активные фильтры.
	 * @param int    $offset      Смещение (пагинация).
	 * @param int    $per_page    Размер страницы.
	 *
	 * @return array{0: TaskListItemDTO[], 1: int}
	 */
	public function fetchTasks( string $subject_key, array $filters, int $offset, int $per_page ): array {
		$post_type   = PostTypeResolver::tasks( $subject_key );
		$taxonomies  = $this->requiredTaxonomies( $subject_key );

		$args = array(
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = (string) $filters['search'];
		}

		$tax_query = $this->buildTaxQuery( $subject_key, $taxonomies, $filters['taxonomies'] ?? array() );
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$result = $this->post_manager->query( $post_type, $args );
		$tasks  = array_map(
			fn( \WP_Post $post ) => $this->buildTaskItem( $post, $subject_key, $taxonomies ),
			$result['posts']
		);

		return array( $tasks, $result['total'] );
	}

	/**
	 * Обязательные таксономии предмета — единственные, которые видит посетитель.
	 *
	 * Обязательная = проставлена у каждого задания, поэтому по ней можно фильтровать
	 * и показывать её термины тегами. Необязательные (служебные пометки автора
	 * предмета вроде источника) в фильтры, теги и tax_query не попадают.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return TaxonomyDataDTO[]
	 */
	private function requiredTaxonomies( string $subject_key ): array {
		return array_values(
			array_filter(
				$this->taxonomy_repository->getBySubject( $subject_key ),
				static fn( TaxonomyDataDTO $taxonomy ) => $taxonomy->is_required
			)
		);
	}

	/**
	 * Собирает tax_query из выбранных фильтров, отбрасывая неизвестные таксономии.
	 *
	 * @param string            $subject_key Ключ предмета.
	 * @param TaxonomyDataDTO[] $taxonomies  Пользовательские таксономии предмета.
	 * @param array             $selected    [tax_slug => term_slugs] из запроса.
	 *
	 * @return array
	 */
	private function buildTaxQuery( string $subject_key, array $taxonomies, array $selected ): array {
		$allowed = array( PostTypeResolver::getTaskTaxonomy( $subject_key ) );
		foreach ( $taxonomies as $taxonomy ) {
			$allowed[] = $taxonomy->slug;
		}

		$tax_query = array();

		foreach ( $selected as $tax_slug => $term_slugs ) {
			if ( ! in_array( $tax_slug, $allowed, true ) ) {
				continue;
			}

			$term_slugs = array_values( array_filter( array_map( 'sanitize_key', (array) $term_slugs ) ) );
			if ( empty( $term_slugs ) ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $tax_slug,
				'field'    => 'slug',
				'terms'    => $term_slugs,
				'operator' => 'IN',
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		return $tax_query;
	}

	/**
	 * Собирает DTO одной карточки задания.
	 *
	 * @param \WP_Post          $post        Пост задания.
	 * @param string            $subject_key Ключ предмета.
	 * @param TaxonomyDataDTO[] $taxonomies  Пользовательские таксономии предмета.
	 *
	 * @return TaskListItemDTO
	 */
	private function buildTaskItem( \WP_Post $post, string $subject_key, array $taxonomies ): TaskListItemDTO {
		$meta = $this->post_manager->getMeta( $post->ID, PostMetaName::Meta->value );
		$meta = is_array( $meta ) ? $meta : array();

		$number_tax  = PostTypeResolver::getTaskTaxonomy( $subject_key );
		$number_term = $this->term_manager->getPostTerms( $post->ID, $number_tax )[0] ?? null;

		return new TaskListItemDTO(
			id:            $post->ID,
			title:         (string) get_the_title( $post->ID ),
			url:           (string) get_permalink( $post->ID ),
			task_number:          $number_term ? (int) $number_term->name : 0,
			task_type_url:        $number_term ? $this->term_manager->getLink( $number_term->term_id, $number_tax ) : '',
			task_number_taxonomy: $number_tax,
			task_number_slug:     $number_term ? $number_term->slug : '',
			task_number_color:    $this->tag_palette->colorIndex( $subject_key, $number_tax ),
			tags:                 $this->buildTags( $post->ID, $subject_key, $taxonomies ),
			condition:     $this->task_meta_service->getCombinedCondition( $meta ),
			answer:        (string) ( $meta['task_answer'] ?? '' ),
			files:         $this->task_meta_service->getTaskFiles( $meta ),
		);
	}

	/**
	 * Теги-классификаторы задания из пользовательских таксономий предмета.
	 *
	 * @param int               $post_id     ID задания.
	 * @param string            $subject_key Ключ предмета (для палитры чипов).
	 * @param TaxonomyDataDTO[] $taxonomies  Пользовательские таксономии предмета.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildTags( int $post_id, string $subject_key, array $taxonomies ): array {
		$tags = array();

		foreach ( $taxonomies as $taxonomy ) {
			$color = $this->tag_palette->colorIndex( $subject_key, $taxonomy->slug );

			foreach ( $this->term_manager->getPostTerms( $post_id, $taxonomy->slug ) as $term ) {
				$tags[] = array(
					'taxonomy'      => $taxonomy->slug,
					'taxonomy_name' => $taxonomy->name,
					'label'         => $term->name,
					'slug'          => $term->slug,
					'url'           => $this->term_manager->getLink( $term->term_id, $taxonomy->slug ),
					'color'         => $color,
				);
			}
		}

		return $tags;
	}

	/**
	 * Группы фильтров сайдбара: тип задания + обязательные таксономии предмета.
	 * Пустые (без терминов) группы отбрасываются.
	 *
	 * Опции каждой группы считаются как фасеты: доступны только те термины, что
	 * встречаются среди заданий, прошедших ОСТАЛЬНЫЕ активные фильтры. Собственный
	 * фильтр группы из ограничения исключён — иначе выбор схлопнул бы её же список
	 * до единственного значения и снять/сменить его было бы нечем.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param array  $filters     Активные фильтры: ['search' => string, 'taxonomies' => [tax => term_slugs]].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function buildFilters( string $subject_key, array $filters = array() ): array {
		$selected = $filters['taxonomies'] ?? array();
		$search   = (string) ( $filters['search'] ?? '' );

		$number_tax = PostTypeResolver::getTaskTaxonomy( $subject_key );
		$sources    = array( array( $number_tax, 'Тип задания', true ) );

		foreach ( $this->requiredTaxonomies( $subject_key ) as $taxonomy ) {
			$sources[] = array( $taxonomy->slug, $taxonomy->name, false );
		}

		$groups    = array();
		$ids_cache = array();

		foreach ( $sources as [ $tax_slug, $name, $is_type ] ) {
			$constraint = $selected;
			unset( $constraint[ $tax_slug ] );

			$counts = $this->facetCounts( $subject_key, $search, $constraint, $tax_slug, $ids_cache );
			$terms  = $this->filter_groups->options( $tax_slug, $counts, $selected[ $tax_slug ] ?? array(), $tax_slug === $number_tax );

			if ( empty( $terms ) ) {
				continue;
			}

			$groups[] = $this->filter_groups->group( $tax_slug, $name, $terms, $is_type );
		}

		return $groups;
	}

	/**
	 * Счётчики терминов таксономии в пределах текущего среза заданий.
	 *
	 * Без активных фильтров считаем по всему CPT (один дешёвый запрос), иначе —
	 * по списку ID заданий, прошедших ограничение. Список ID кешируется на время
	 * сборки: у всех групп, кроме той, чей фильтр активен, ограничение одинаковое.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param string $search      Поисковая строка.
	 * @param array  $constraint  Фильтры-ограничения: [tax_slug => term_slugs].
	 * @param string $taxonomy    Таксономия, термины которой считаем.
	 * @param array  $ids_cache   Кеш списков ID (по ключу ограничения), передаётся по ссылке.
	 *
	 * @return array<int, int> [term_id => count]
	 */
	private function facetCounts( string $subject_key, string $search, array $constraint, string $taxonomy, array &$ids_cache ): array {
		if ( '' === $search && empty( $constraint ) ) {
			return $this->term_manager->countPostsByType( $taxonomy, PostTypeResolver::tasks( $subject_key ) );
		}

		ksort( $constraint );
		$cache_key = md5( wp_json_encode( array( $search, $constraint ) ) );

		if ( ! isset( $ids_cache[ $cache_key ] ) ) {
			$ids_cache[ $cache_key ] = $this->matchingIds( $subject_key, $search, $constraint );
		}

		return $this->term_manager->countPostsByIds( $taxonomy, $ids_cache[ $cache_key ] );
	}

	/**
	 * ID заданий, удовлетворяющих поиску и набору фильтров.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param string $search      Поисковая строка.
	 * @param array  $selected    Фильтры: [tax_slug => term_slugs].
	 *
	 * @return int[]
	 */
	private function matchingIds( string $subject_key, string $search, array $selected ): array {
		$args = array(
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$tax_query = $this->buildTaxQuery( $subject_key, $this->requiredTaxonomies( $subject_key ), $selected );
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$result = $this->post_manager->query( PostTypeResolver::tasks( $subject_key ), $args );

		return array_map( 'intval', $result['posts'] );
	}

}