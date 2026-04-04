<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Controllers\SubjectController;


/**
 * Class TaskCreationCallbacks
 *
 * Обработчики AJAX-запросов для создания заданий.
 * Отвечает за:
 * - Получение типов заданий для модального окна
 * - Создание заданий с автоматической генерацией номера
 *
 * Хуки регистрируются в TaskCreationController
 *
 * @package Inc\Callbacks
 */
class TaskCreationCallbacks extends BaseController {
	/**
	 * Контроллер предметов для доступа к методам получения типов заданий.
	 *
	 * @var SubjectController
	 */
	protected SubjectController $subjectController;

	/**
	 * Конструктор.
	 *
	 * @param SubjectController $subjectController Контроллер предметов
	 */
	public function __construct( SubjectController $subjectController ) {
		parent::__construct();
		$this->subjectController = $subjectController;
	}

// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Получение типов заданий для модального окна.
	 *
	 * AJAX-обработчик, возвращающий список типов заданий (терминов таксономии)
	 * для указанного предмета.
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
	 */
	public function ajaxGetTypes(): void {
		// Получаем ключ предмета из GET-параметра
		$subject_key = sanitize_text_field( $_GET['subject_key'] ?? '' );

		// Валидация: предмет обязателен
		if ( empty( $subject_key ) ) {
			wp_send_json_error( 'Предмет не указан' );
		}

		// Получаем типы заданий через контроллер предмета
		$types = $this->subjectController->get_task_types_from_tax( $subject_key );

		// Возвращаем успешный ответ с данными
		wp_send_json_success( $types );
	}

	/**
	 * Создание задачи с автоматической генерацией номера.
	 *
	 * AJAX-обработчик, который:
	 * 1. Проверяет nonce для безопасности
	 * 2. Валидирует входные данные
	 * 3. Генерирует уникальный slug на основе типа задания и порядкового номера
	 * 4. Создаёт пост-задание
	 * 5. Привязывает к таксономии и добавляет мета-поля
	 * 6. Возвращает ссылку на редактирование
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
	 */
	public function ajaxCreateTask(): void {
		// Проверка nonce для защиты от CSRF
		check_ajax_referer( 'fs_task_creation_nonce', 'nonce' );

		// Получение и санитизация входных данных
		$subject_key = sanitize_text_field( $_POST['subject_key'] ?? '' );
		$term_id     = absint( $_POST['term_id'] ?? 0 );
		$title       = sanitize_text_field( $_POST['title'] ?? 'Новое задание' );

		// Валидация обязательных полей
		if ( empty( $subject_key ) || $term_id === 0 ) {
			wp_send_json_error( 'Недостаточно данных для создания задания' );

			return;
		}

		// Формируем имя таксономии для данного предмета
		$taxonomy = "{$subject_key}_task_number";

		// Получаем термин (тип задания) по ID
		$term = get_term( $term_id, $taxonomy );

		// Проверяем, что термин существует и не содержит ошибок
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( 'Тип задания не найден' );
		}

		// Извлекаем числовой префикс из slug термина (например, "inf_1" → 1)
		$type_prefix = $this->extractNumberFromSlug( $term->slug );

		// Fallback: если цифры не найдены, используем term_id
		if ( $type_prefix === 0 ) {
			$type_prefix = $term->term_id;
		}

		// Подсчёт уже существующих заданий этого типа
		$current_count = $this->getExistingTasksCount( $taxonomy, $term_id );

		/// Генерируем slug: префикс + трёхзначный порядковый номер (например, 1004, 2015)
		$custom_slug = $type_prefix . str_pad( $current_count, 3, '0', STR_PAD_LEFT );

		// Создаём пост-задание в черновике
		$new_id = wp_insert_post( [
			'post_title'  => $title,
			'post_name'   => $custom_slug,
			'post_type'   => "{$subject_key}_tasks",
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		], true );

		// Проверка на ошибки при создании
		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( 'Ошибка при создании задания: ' . $new_id->get_error_message() );

			return;
		}

		// Привязываем задание к термину (типу задания)
		wp_set_object_terms( $new_id, $term_id, $taxonomy );

		$preferred_template = get_term_meta( $term_id, '_fs_lms_preferred_template', true );

		// 2. Если шаблон не задан (пусто в базе), используем 'standard_task' как фоллбек
		$allowed_templates = apply_filters( 'fs_lms_get_templates', [] );
		if ( empty( $preferred_template ) || ! array_key_exists( $preferred_template, $allowed_templates ) ) {
			$preferred_template = 'standard_task';
		}

		// 3. Сохраняем этот шаблон в метаданные нового ПОСТА
		update_post_meta( $new_id, '_fs_lms_template_type', $preferred_template );

		// Возвращаем успешный ответ с ссылкой на редактирование
		wp_send_json_success( [
			'redirect' => get_edit_post_link( $new_id, 'abs' )
		] );
	}

// ============================ ВСПОМОГАТЕЛЬНЫЙ ФУНКЦИОНАЛ ============================ //

	/**
	 * Извлекает последнюю последовательность цифр из slug.
	 *
	 * Примеры:
	 *   inf_1        → 1
	 *   geometry_42  → 42
	 *
	 * @param string $slug Слаг термина таксономии
	 *
	 * @return int Извлечённое число или 0, если цифры не найдены
	 */
	private function extractNumberFromSlug( string $slug ): int {
		// Ищем последнюю группу цифр в конце или после подчёркивания
		if ( preg_match( '/(\d+)$/', $slug, $matches ) ) {
			return (int) $matches[1];
		}

		// Альтернативный вариант: любые цифры в слаге (на случай необычных форматов)
		if ( preg_match( '/(\d+)/', $slug, $matches ) ) {
			return (int) $matches[1];
		}

		// fallback, если цифры не найдены
		return 0;
	}

	/**
	 * Эффективный подсчёт количества заданий данного типа.
	 *
	 * Использует WP_Query с параметром no_found_posts = false,
	 * чтобы получить общее количество записей без загрузки всех данных.
	 *
	 * @param string $taxonomy Имя таксономии (например, "math_task_number")
	 * @param int $term_id ID термина (типа задания)
	 *
	 * @return int Количество заданий, привязанных к данному термину
	 */
	private function getExistingTasksCount( string $taxonomy, int $term_id ): int {
		// Извлекаем ключ предмета из имени таксономии (удаляем суффикс "_task_number")
		$subject_key = str_replace( '_task_number', '', $taxonomy );

		// Создаём запрос для подсчёта заданий определённого типа
		$query = new \WP_Query( [
			'post_type'      => "{$subject_key}_tasks",  // Тип поста задания
			'post_status'    => 'any',                   // Учитываем все статусы
			'posts_per_page' => 1,                       // Не грузим все посты
			'fields'         => 'ids',                   // Получаем только ID
			'no_found_rows'  => false,                   // Обязательно для found_posts
			'tax_query'      => [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				]
			],
		] );

		// Возвращаем количество найденных постов
		return (int) $query->found_posts;
	}
}