<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\BankUsageIndex;
use Inc\Services\Course\ContentUsageService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ProblemListFilters
 *
 * Фильтры и сортировка нативной таблицы банка задач (`fs_lms_problems`):
 * тематика, использование (курс/работа/сирота), автор.
 *
 * @package Inc\Controllers\Builders
 *
 * Разметку печатает шаблон `admin/problems/problem-filters` — здесь только
 * данные и работа с `WP_Query`. Индексы использования строятся лениво:
 * они дорогие (обход всех курсов/работ всех предметов).
 */
class ProblemListFilters {

	use Sanitizer;

	/** Статусы, среди которых ищем авторов для фильтра «Автор». */
	private const AUTHOR_LOOKUP_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'fs_archived' );

	/** Статусы для сортировки по колонке «Использование». */
	private const SORT_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' );

	/** @var array<int, array{int, string, int[]}>|null Кэш индекса курсов на время запроса. */
	private ?array $courseIndex = null;

	/** @var array<int, array{int, string, int[]}>|null Кэш индекса работ на время запроса. */
	private ?array $workIndex = null;

	/**
	 * @param BankUsageIndex      $index Индексы «потребитель → задачи»
	 * @param ContentUsageService $usage Подписи использования (для сортировки колонки)
	 * @param PostManager         $posts Доступ к записям банка
	 */
	public function __construct(
		private readonly BankUsageIndex      $index,
		private readonly ContentUsageService $usage,
		private readonly PostManager         $posts,
	) {}

	/**
	 * Данные фильтров для шаблона.
	 *
	 * @return array{
	 *     tag: array{name: string, options: array, selected: string, all_label: string}|null,
	 *     usage: array{selected: string, courses: array<int,string>, works: array<int,string>},
	 *     author: array{name: string, options: array, selected: string, all_label: string}|null
	 * }
	 */
	public function data(): array {
		return array(
			'tag'    => $this->tagSelect(),
			'usage'  => array(
				'selected' => $this->sanitizeGetKey( 'fs_problem_usage' ),
				'courses'  => $this->index->consumerOptions( $this->courses() ),
				'works'    => $this->index->consumerOptions( $this->works() ),
			),
			'author' => $this->authorSelect(),
		);
	}

	/**
	 * Применяет сортировку и фильтр использования к списку задач.
	 *
	 * @param \WP_Query $query Основной запрос экрана
	 *
	 * @return void
	 */
	public function apply( \WP_Query $query ): void {
		$this->applyUsageSort( $query );
		$this->applyUsageFilter( $query );
	}

	/**
	 * Сортировка по колонке «Использование».
	 *
	 * Сортируем по тому же тексту, что показывает колонка (названия курсов/тестов
	 * через ContentUsageService), а не по числу использований — иначе порядок
	 * строк не совпадает с видимыми в колонке подписями.
	 *
	 * @param \WP_Query $query Запрос
	 *
	 * @return void
	 */
	private function applyUsageSort( \WP_Query $query ): void {
		if ( 'fs_lms_usage' !== $query->get( 'orderby' ) ) {
			return;
		}

		$all = $this->posts->search( PostTypeResolver::problems(), array(
			'status' => self::SORT_STATUSES,
			'limit'  => -1,
		) );

		$labels = array();
		foreach ( $all as $post ) {
			$paths               = $this->usage->usagePathList( 'problem', $post->ID );
			$labels[ $post->ID ] = mb_strtolower( implode( ', ', array_column( $paths, 'display' ) ), 'UTF-8' );
		}

		usort( $all, static fn( $a, $b ): int => $labels[ $a->ID ] <=> $labels[ $b->ID ] );

		if ( 'DESC' === strtoupper( $query->get( 'order' ) ?: 'ASC' ) ) {
			$all = array_reverse( $all );
		}

		$query->set( 'post__in', array_map( static fn( $p ): int => $p->ID, $all ) );
		$query->set( 'orderby', 'post__in' );
	}

	/**
	 * Фильтр использования: «сирота», курс (`c{ID}`) или работа (`{ID}`).
	 *
	 * @param \WP_Query $query Запрос
	 *
	 * @return void
	 */
	private function applyUsageFilter( \WP_Query $query ): void {
		$usage = $this->sanitizeGetKey( 'fs_problem_usage' );
		if ( '' === $usage ) {
			return;
		}

		if ( 'orphan' === $usage ) {
			$query->set( 'post__not_in', $this->index->usedIds( $this->works() ) );

			return;
		}

		if ( str_starts_with( $usage, 'c' ) && is_numeric( substr( $usage, 1 ) ) ) {
			$ids = $this->index->idsFor( $this->courses(), (int) substr( $usage, 1 ) );
			$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );

			return;
		}

		if ( is_numeric( $usage ) ) {
			$ids = $this->index->idsFor( $this->works(), (int) $usage );
			$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
		}
	}

	/**
	 * Селект тематики (таксономия problem_tag обрабатывается WP нативно).
	 *
	 * @return array{name: string, options: array, selected: string, all_label: string}|null
	 */
	private function tagSelect(): ?array {
		$tags = get_terms( array( 'taxonomy' => 'problem_tag', 'hide_empty' => false ) );
		if ( is_wp_error( $tags ) || empty( $tags ) ) {
			return null;
		}

		$options = array();
		foreach ( $tags as $tag ) {
			$options[ $tag->slug ] = $tag->name;
		}

		return array(
			'name'      => 'problem_tag',
			'options'   => $options,
			'selected'  => $this->sanitizeGetKey( 'problem_tag' ),
			'all_label' => 'Вся тематика',
		);
	}

	/**
	 * Селект «Автор» — только когда авторов больше одного.
	 *
	 * @return array{name: string, options: array, selected: string, all_label: string}|null
	 */
	private function authorSelect(): ?array {
		$problems  = $this->posts->search( PostTypeResolver::problems(), array( 'status' => self::AUTHOR_LOOKUP_STATUSES ) );
		$authorIds = array_unique( array_map( static fn( $p ): int => (int) $p->post_author, $problems ) );

		if ( count( $authorIds ) < 2 ) {
			return null;
		}

		$options = array();
		foreach ( $authorIds as $uid ) {
			$user = get_user_by( 'id', $uid );
			if ( false !== $user ) {
				$options[ $uid ] = $user->display_name;
			}
		}

		return array(
			'name'      => 'author',
			'options'   => $options,
			'selected'  => (string) $this->sanitizeGetInt( 'author' ),
			'all_label' => 'Все авторы',
		);
	}

	/**
	 * Индекс курсов (ленивый, один раз за запрос).
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	private function courses(): array {
		return $this->courseIndex ??= $this->index->coursesByProblem();
	}

	/**
	 * Индекс работ (ленивый, один раз за запрос).
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	private function works(): array {
		return $this->workIndex ??= $this->index->worksByProblem();
	}
}
