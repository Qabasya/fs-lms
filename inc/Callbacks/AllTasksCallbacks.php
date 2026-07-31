<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\Builders\AllTasksDataBuilder;
use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AllTasksCallbacks
 *
 * Коллбеки публичной страницы «Все задания» (тренажёр).
 *
 * Обрабатывает:
 *   1. Подмену шаблона WordPress на архиве CPT заданий (template_include).
 *   2. AJAX-подгрузку отфильтрованного постраничного списка заданий.
 *
 * Публичный доступ (nopriv): capability не проверяется, только nonce.
 *
 * @package Inc\Callbacks
 */
class AllTasksCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly AllTasksDataBuilder $builder,
	) {
		parent::__construct();
	}

	/**
	 * Подключает кастомный шаблон архива заданий предмета.
	 *
	 * Срабатывает на фильтре template_include, заменяет шаблон темы только на
	 * архивных страницах CPT вида {subject}_tasks.
	 *
	 * @param string $template Путь к текущему шаблону.
	 *
	 * @return string
	 */
	public function loadAllTasksTemplate( string $template ): string {
		if ( ! is_post_type_archive() ) {
			return $template;
		}

		$queried = get_queried_object();

		if ( ! ( $queried instanceof \WP_Post_Type ) ) {
			return $template;
		}

		if ( ! PostTypeResolver::isTaskPostType( $queried->name ) ) {
			return $template;
		}

		$custom_template = FS_LMS_PATH . 'templates/frontend/all-tasks.php';

		if ( ! file_exists( $custom_template ) ) {
			return $template;
		}

		$subject_key = PostTypeResolver::subjectFromTaskPostType( $queried->name );
		set_query_var( 'fs_all_tasks_data', $this->builder->getPageData( $subject_key ) );

		return $custom_template;
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
			'taxonomies' => $this->parseTaxonomyFilters(),
		);

		[ $tasks, $total ] = $this->builder->fetchTasks( $subject_key, $filters, $offset, $per_page );

		$this->success( array(
			'tasks'    => array_map( static fn( $task ) => $task->toArray(), $tasks ),
			'total'    => $total,
			'has_more' => ( $offset + count( $tasks ) ) < $total,
		) );
	}

	/**
	 * Разбирает и санитизирует карту фильтров из POST: [taxonomy_slug => term_slugs].
	 *
	 * @return array<string, string[]>
	 */
	private function parseTaxonomyFilters(): array {
		$raw = $this->unslashArray( 'filters' );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$result = array();

		foreach ( $raw as $taxonomy => $slugs ) {
			$tax = $this->sanitizeKeyValue( $taxonomy );

			if ( '' === $tax || ! is_array( $slugs ) ) {
				continue;
			}

			$clean = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );

			if ( ! empty( $clean ) ) {
				$result[ $tax ] = $clean;
			}
		}

		return $result;
	}
}