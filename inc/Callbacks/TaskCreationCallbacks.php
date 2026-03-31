<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Controllers\SubjectController;

class TaskCreationCallbacks extends BaseController {
	protected SubjectController $subjectController;

	public function __construct( SubjectController $subjectController ) {
		parent::__construct();
		$this->subjectController = $subjectController;

		$this->registerAjaxActions();
	}

	/**
	 * Центральное место регистрации всех AJAX-действий
	 */
	private function registerAjaxActions(): void {
		add_action( 'wp_ajax_fs_get_task_types', [ $this, 'ajaxGetTypes' ] );
		add_action( 'wp_ajax_fs_create_task_action', [ $this, 'ajaxCreateTask' ] );
	}
// ============================ AJAX-КОЛЛБЕКИ ============================ //
	/**
	 * Получение типов заданий для модалки
	 */
	public function ajaxGetTypes(): void {
		$subject_key = sanitize_text_field( $_GET['subject_key'] ?? '' );

		if ( empty( $subject_key ) ) {
			wp_send_json_error( 'Предмет не указан' );
		}

		$types = $this->subjectController->get_task_types_from_tax( $subject_key );
		wp_send_json_success( $types );
	}

	/**
	 * Создание задачи с генерацией номера
	 */
	public function ajaxCreateTask(): void {
		check_ajax_referer( 'fs_task_creation_nonce', 'nonce' );

		$subject_key = sanitize_text_field( $_POST['subject_key'] ?? '' );
		$term_id     = absint( $_POST['term_id'] ?? 0 );
		$title       = sanitize_text_field( $_POST['title'] ?? 'Новое задание' );

		if ( empty( $subject_key ) || $term_id === 0 ) {
			wp_send_json_error( 'Недостаточно данных для создания задания' );

			return;
		}

		$taxonomy = "{$subject_key}_task_number";
		$term     = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( 'Тип задания не найден' );
		}

		// Извлекаем числовой префикс из slug
		$type_prefix = $this->extractNumberFromSlug( $term->slug );

		// Если по какой-то причине цифр нет — fallback на term_id (маловероятно)
		if ( $type_prefix === 0 ) {
			$type_prefix = $term->term_id;
		}

		// Подсчёт уже существующих заданий этого типа
		$current_count = $this->getExistingTasksCount( $taxonomy, $term_id );

		// Генерируем slug вида: 1004, 2015 и т.д.
		$custom_slug = $type_prefix . str_pad( $current_count, 3, '0', STR_PAD_LEFT );


		$new_id = wp_insert_post( [
			'post_title'  => $title,
			'post_name'   => $custom_slug,
			'post_type'   => "{$subject_key}_tasks",
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		], true );

		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( 'Ошибка при создании задания: ' . $new_id->get_error_message() );

			return;
		}
		wp_set_object_terms( $new_id, $term_id, $taxonomy );
		update_post_meta( $new_id, '_fs_lms_template_type', 'standard_task' );

		wp_send_json_success( [
			'redirect' => get_edit_post_link( $new_id, 'abs' )
		] );
	}

// ============================ ВСПОМОГАТЕЛЬНЫЙ ФУНКЦИОНАЛ ============================ //

	/**
	 * Извлекает последнюю последовательность цифр из slug
	 * Примеры:
	 *   inf_1        → 1
	 *   geometry_42  → 42
	 *   test_7_extra → 7
	 *   chert_105    → 105
	 */
	private function extractNumberFromSlug(string $slug): int
	{
		// Ищем последнюю группу цифр в конце или после подчёркивания
		if (preg_match('/(\d+)$/', $slug, $matches)) {
			return (int) $matches[1];
		}

		// Альтернативный вариант: любые цифры в слаге (на случай необычных форматов)
		if (preg_match('/(\d+)/', $slug, $matches)) {
			return (int) $matches[1];
		}

		return 0; // fallback
	}

	/**
	 * Эффективный подсчёт количества заданий данного типа
	 */
	private function getExistingTasksCount(string $taxonomy, int $term_id): int
	{
		$subject_key = str_replace('_task_number', '', $taxonomy);

		$query = new \WP_Query([
			'post_type'      => "{$subject_key}_tasks",
			'post_status'    => 'any',
			'posts_per_page' => 1,           // важно! не грузим все посты
			'fields'         => 'ids',
			'no_found_rows'  => false,       // обязательно для found_posts
			'tax_query'      => [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				]
			],
		]);

		return (int) $query->found_posts;
	}
}