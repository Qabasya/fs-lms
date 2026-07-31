<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\DTO\AllTasksPageDTO;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\DTO\TaskListItemDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;
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

	public function __construct(
		private SubjectRepository  $subject_repository,
		private TaxonomyRepository $taxonomy_repository,
		private PostManager        $post_manager,
		private TermManager        $term_manager,
		private TaskMetaService    $task_meta_service,
	) {}

	/**
	 * Полный DTO страницы для первичного рендера.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return AllTasksPageDTO
	 */
	public function getPageData( string $subject_key ): AllTasksPageDTO {
		$subject      = $this->subject_repository->getByKey( $subject_key );
		$subject_name = $subject?->name ?? $subject_key;

		[ $tasks, $total ] = $this->fetchTasks( $subject_key, array(), 0, self::PER_PAGE );

		return new AllTasksPageDTO(
			subject_key:  $subject_key,
			subject_name: $subject_name,
			filters:      $this->buildFilters( $subject_key ),
			tasks:        $tasks,
			total:        $total,
			per_page:     self::PER_PAGE,
			has_more:     count( $tasks ) < $total,
			nonce:        Nonce::AllTasks->create(),
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
		$taxonomies  = $this->taxonomy_repository->getBySubject( $subject_key );

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
			tags:                 $this->buildTags( $post->ID, $taxonomies ),
			condition:     $this->task_meta_service->getCombinedCondition( $meta ),
			answer:        (string) ( $meta['task_answer'] ?? '' ),
			files:         $this->task_meta_service->getTaskFiles( $meta ),
		);
	}

	/**
	 * Теги-классификаторы задания из пользовательских таксономий предмета.
	 *
	 * @param int               $post_id    ID задания.
	 * @param TaxonomyDataDTO[] $taxonomies Пользовательские таксономии предмета.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildTags( int $post_id, array $taxonomies ): array {
		$tags = array();

		foreach ( $taxonomies as $taxonomy ) {
			foreach ( $this->term_manager->getPostTerms( $post_id, $taxonomy->slug ) as $term ) {
				$tags[] = array(
					'taxonomy'      => $taxonomy->slug,
					'taxonomy_name' => $taxonomy->name,
					'label'         => $term->name,
					'slug'          => $term->slug,
					'url'           => $this->term_manager->getLink( $term->term_id, $taxonomy->slug ),
				);
			}
		}

		return $tags;
	}

	/**
	 * Группы фильтров сайдбара: тип задания + пользовательские таксономии предмета.
	 * Пустые (без терминов) группы отбрасываются.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildFilters( string $subject_key ): array {
		$groups = array();

		$number_tax   = PostTypeResolver::getTaskTaxonomy( $subject_key );
		$number_terms = $this->buildTermOptions( $number_tax );
		if ( ! empty( $number_terms ) ) {
			$groups[] = array(
				'taxonomy' => $number_tax,
				'name'     => 'Тип задания',
				'terms'    => $number_terms,
				'summary'  => $this->filterSummary( $number_terms, true ),
			);
		}

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $taxonomy ) {
			$terms = $this->buildTermOptions( $taxonomy->slug );
			if ( empty( $terms ) ) {
				continue;
			}

			$groups[] = array(
				'taxonomy' => $taxonomy->slug,
				'name'     => $taxonomy->name,
				'terms'    => $terms,
				'summary'  => $this->filterSummary( $terms, false ),
			);
		}

		return $groups;
	}

	/**
	 * Текст-сводка группы фильтров (виден, когда секция свёрнута и ничего не выбрано).
	 * Год (все термины — 4-значные числа) → диапазон «min—max»; тип → «Все N типов»;
	 * прочее → «Все N».
	 *
	 * @param array $terms   Опции группы: [{slug,name,count,url}].
	 * @param bool  $is_type Группа типа задания (для склонения «тип/типа/типов»).
	 *
	 * @return string
	 */
	private function filterSummary( array $terms, bool $is_type ): string {
		$count = count( $terms );

		$years = array_filter( $terms, static fn( $t ) => (bool) preg_match( '/^\d{4}$/', (string) $t['name'] ) );
		if ( $count > 0 && count( $years ) === $count ) {
			$nums = array_map( static fn( $t ) => (int) $t['name'], $terms );
			$min  = min( $nums );
			$max  = max( $nums );

			return $min === $max ? (string) $min : "{$min}—{$max}";
		}

		if ( $is_type ) {
			return sprintf( 'Все %d %s', $count, $this->pluralTypes( $count ) );
		}

		return sprintf( 'Все %d', $count );
	}

	/**
	 * Склонение слова «тип» по числу: 1 тип, 2–4 типа, 5+ типов.
	 *
	 * @param int $n Количество.
	 *
	 * @return string
	 */
	private function pluralTypes( int $n ): string {
		$m10  = $n % 10;
		$m100 = $n % 100;

		if ( 1 === $m10 && 11 !== $m100 ) {
			return 'тип';
		}
		if ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) {
			return 'типа';
		}

		return 'типов';
	}

	/**
	 * Опции одной группы фильтров: термины таксономии с счётчиками.
	 *
	 * @param string $taxonomy Слаг таксономии.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildTermOptions( string $taxonomy ): array {
		return array_map(
			fn( \WP_Term $term ) => array(
				'slug'  => $term->slug,
				'name'  => $term->name,
				'count' => (int) $term->count,
				'url'   => $this->term_manager->getLink( $term->term_id, $taxonomy ),
			),
			$this->term_manager->getAll( $taxonomy )
		);
	}
}