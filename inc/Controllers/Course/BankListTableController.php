<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Controllers\Builders\BankListFilters;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BankListTableController
 *
 * Фильтры нативных list-table банков контента: селекты над таблицей
 * (restrict_manage_posts), применение к запросу (pre_get_posts) и подпись
 * «Незавершённая» вместо «Черновик» у задач банка (display_post_states).
 *
 * Выделен из LearningMenuController (Т14.1). Логика фильтров — BankListFilters.
 *
 * @package Inc\Controllers\Course
 */
class BankListTableController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct(
		private readonly BankListFilters $filters,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Фильтры по типу работы / виду контрольной / использованию / автору в list table.
		add_action( 'restrict_manage_posts', array( $this, 'renderTypeFilter' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'applyTypeFilter' ) );

		// «Незавершённая» вместо стандартного «Черновик» для задач банка.
		add_filter( 'display_post_states', array( $this, 'filterTaskDraftState' ), 10, 2 );
	}

	/**
	 * Фильтры банка над нативной таблицей (хук restrict_manage_posts).
	 *
	 * @param string $post_type CPT экрана
	 * @param string $which     Позиция панели: top|bottom
	 *
	 * @return void
	 */
	public function renderTypeFilter( string $post_type, string $which = 'top' ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$selects = $this->filters->selectsFor( $post_type );
		if ( empty( $selects ) ) {
			return;
		}

		$this->render( 'admin/learning/bank-filters', compact( 'selects' ) );
	}

	/**
	 * @param array<string,string> $states
	 */
	public function filterTaskDraftState( array $states, \WP_Post $post ): array {
		if ( PostTypeResolver::isTaskPostType( $post->post_type ) && isset( $states['draft'] ) ) {
			$states['draft'] = __( 'Незавершённая', 'fs-lms' );
		}
		return $states;
	}

	/**
	 * Применяет фильтры банка к списку (хук pre_get_posts).
	 *
	 * @param \WP_Query $query Запрос экрана
	 *
	 * @return void
	 */
	public function applyTypeFilter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$this->filters->apply( $query );
	}
}
