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
	private function registerAjaxActions(): void
	{
		add_action('wp_ajax_fs_get_task_types', [$this, 'ajaxGetTypes']);
		add_action('wp_ajax_fs_create_task_action', [$this, 'ajaxCreateTask']);
	}
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
		$term_id     = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$title       = sanitize_text_field( $_POST['title'] ?? 'Новое задание' );

		if ( ! $term_id || ! $subject_key ) {
			wp_send_json_error( 'Недостаточно данных' );
		}

		$taxonomy = "{$subject_key}_task_number";
		$term     = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( 'Тип задания не найден' );
		}

		// Извлекаем цифру из слага (chert_1 -> 1)
		$type_prefix = preg_replace( '/[^0-9]/', '', $term->slug );
		$type_prefix = empty( $type_prefix ) ? $term->term_id : $type_prefix;

		// Считаем посты этого типа
		$query = new \WP_Query( [
			'post_type'      => "{$subject_key}_tasks",
			'post_status'    => 'any',
			'posts_per_page' => - 1,
			'tax_query'      => [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				]
			]
		] );

		$custom_slug = $type_prefix . str_pad( $query->found_posts, 3, '0', STR_PAD_LEFT );

		$new_id = wp_insert_post( [
			'post_title'  => $title,
			'post_name'   => $custom_slug,
			'post_type'   => "{$subject_key}_tasks",
			'post_status' => 'draft'
		] );

		if ( ! is_wp_error( $new_id ) ) {
			wp_set_object_terms( $new_id, $term_id, $taxonomy );
			update_post_meta( $new_id, '_fs_lms_template_type', 'standard_task' );

			wp_send_json_success( [
				'redirect' => get_edit_post_link( $new_id, 'abs' )
			] );
		}

		wp_send_json_error( 'Ошибка при создании записи' );
	}
}