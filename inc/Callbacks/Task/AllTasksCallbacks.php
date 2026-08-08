<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Controllers\Builders\AllTasksDataBuilder;
use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Task\TaskFilterParser;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AllTasksCallbacks
 *
 * AJAX-подгрузка отфильтрованного постраничного списка заданий для тренажёра
 * (первичный рендер собирает SubjectLandingController).
 *
 * Публичный доступ (nopriv): capability не проверяется, только nonce.
 *
 * @package Inc\Callbacks\Task
 */
class AllTasksCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly AllTasksDataBuilder $builder,
		private readonly TaskFilterParser    $filters,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: постраничный список заданий с фильтрами.
	 *
	 * POST:
	 *   - security    (string)                nonce
	 *   - subject_key (string)                ключ предмета
	 *   - offset      (int)                   смещение
	 *   - per_page    (int)                   размер страницы (макс. 50)
	 *   - search      (string)                поисковая строка
	 *   - filters     (array<string,string[]>) [taxonomy_slug => term_slugs]
	 *
	 * @return void
	 */
	public function ajaxFetchAllTasks(): void {
		Nonce::AllTasks->verify();

		$subject_key = $this->requireKey( 'subject_key' );
		$offset      = $this->sanitizeInt( 'offset' );
		$per_page    = $this->sanitizeInt( 'per_page' ) ?: AllTasksDataBuilder::PER_PAGE;
		$per_page    = min( max( $per_page, 1 ), 50 );

		$filters = array(
			'search'     => $this->sanitizeText( 'search' ),
			'taxonomies' => $this->filters->fromRequest(),
		);

		[ $tasks, $total ] = $this->builder->fetchTasks( $subject_key, $filters, $offset, $per_page );

		$this->success( array(
			'tasks'    => array_map( static fn( $task ) => $task->toArray(), $tasks ),
			'total'    => $total,
			'has_more' => ( $offset + count( $tasks ) ) < $total,
			// Статьи сайдбара зависят от выбранных типов задания — отдаём при
			// перезагрузке списка (offset = 0), при догрузке они не меняются.
			'articles' => 0 === $offset ? $this->builder->fetchArticles( $subject_key, $filters['taxonomies'] ) : null,
			// Фасеты: доступность и счётчики опций сайдбара пересчитываются под
			// текущий срез. При догрузке страницы срез тот же — не пересылаем.
			'filters'  => 0 === $offset ? $this->builder->buildFilters( $subject_key, $filters ) : null,
		) );
	}
}