<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\TaskTypeRepository;

/**
 * Class TaskCreationCallbacks
 *
 * AJAX-обработчики для создания новых заданий.
 * Отвечает только за процесс создания поста задания:
 * получение типов, вставку поста, slug-генерацию и назначение мета-данных.
 *
 * @package Inc\Callbacks
 */
class TaskCreationCallbacks extends BaseController
{
	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository  $metaboxes Репозиторий привязок шаблонов и типов заданий
	 * @param TaskTypeRepository $taskTypes Репозиторий типовых условий (boilerplate)
	 */
	public function __construct(
		private MetaBoxRepository $metaboxes,
		private TaskTypeRepository $taskTypes
	) {
		parent::__construct();
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Возвращает типы заданий для выпадающего списка в модальном окне создания.
	 *
	 * @return void
	 */
	public function ajaxGetTypes(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_task_creation_nonce', 'nonce');

		// Проверка прав доступа (редактирование постов)
		if (!current_user_can('edit_posts')) {
			wp_send_json_error('Доступ запрещён', 403);
			return;
		}

		// Получение и санитизация ключа предмета
		$subject_key = sanitize_text_field(wp_unslash($_GET['subject_key'] ?? ''));

		if (empty($subject_key)) {
			wp_send_json_error('Предмет не указан');
			return;
		}

		wp_send_json_success(
			$this->metaboxes->getTaskTypes($subject_key)
		);
	}

	/**
	 * Создаёт новое задание: пост + мета-данные + boilerplate.
	 *
	 * @return void
	 */
	public function ajaxCreateTask(): void
	{
		$this->validateCreateTaskRequest();
		[$subject_key, $term_id, $title] = $this->collectCreateTaskData();

		// НОВОЕ: получаем конкретный UID шаблона, который выбрал пользователь
		$boilerplate_uid = sanitize_text_field(wp_unslash($_POST['boilerplate_uid'] ?? ''));

		$taxonomy = "{$subject_key}_task_number";
		$term     = get_term($term_id, $taxonomy);

		if (!$term || is_wp_error($term)) {
			wp_send_json_error('Тип задания не найден');
			return;
		}

		$term_slug = (string) $term->slug;
		$task_text = '';

		// Если выбран конкретный шаблон, ищем его
		if (!empty($boilerplate_uid)) {
			$bp = $this->taskTypes->findBoilerplate($subject_key, $term_slug, $boilerplate_uid);
			$task_text = $bp ? $bp->content : '';
		}

		// Создание поста (используем твой существующий метод)
		$new_id = $this->insertTaskPost($subject_key, $taxonomy, $term_id, $term, $term_slug, $title, $task_text);

		if (is_wp_error($new_id)) {
			wp_send_json_error('Ошибка базы данных: ' . $new_id->get_error_message());
			return;
		}

		$this->applyPostMeta($new_id, $subject_key, $term_slug, $task_text);

		wp_send_json_success(['redirect' => get_edit_post_link($new_id, 'abs')]);
	}

	public function ajaxGetBoilerplates(): void
	{
		check_ajax_referer('fs_task_creation_nonce', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error('Доступ запрещён', 403);
			return;
		}

		$subject_key = sanitize_text_field(wp_unslash($_GET['subject_key'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_GET['term_slug'] ?? ''));

		if (empty($subject_key) || empty($term_slug)) {
			wp_send_json_error('Недостаточно данных 2');
			return;
		}

		// Получаем все варианты из репозитория
		$variants = $this->taskTypes->getBoilerplates($subject_key, $term_slug);

		// Формируем легкий список для выпадашки
		$response = array_map(static fn($bp) => [
			'uid'   => $bp->uid,
			'title' => $bp->title,
		], $variants);

		wp_send_json_success($response);
	}
	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет nonce и права доступа для запроса создания задания.
	 *
	 * @return void
	 */
	private function validateCreateTaskRequest(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_task_creation_nonce', 'nonce');

		// Проверка прав доступа (редактирование постов)
		if (!current_user_can('edit_posts')) {
			wp_send_json_error('У вас недостаточно прав для создания задания', 403);
		}
	}

	/**
	 * Собирает и валидирует POST-данные запроса.
	 *
	 * @return array{0: string, 1: int, 2: string} [subject_key, term_id, title]
	 */
	private function collectCreateTaskData(): array
	{
		$subject_key = sanitize_text_field(wp_unslash($_POST['subject_key'] ?? ''));
		$term_id     = absint($_POST['term_id'] ?? 0);
		$title       = sanitize_text_field(wp_unslash($_POST['title'] ?? 'Новое задание'));

		// Валидация обязательных полей
		if (empty($subject_key) || $term_id === 0) {
			wp_send_json_error('Недостаточно данных 3');
			// return нужен для статических анализаторов (psalm/phpstan):
			// wp_send_json_error завершает выполнение через wp_die()
			return [];
		}

		return [$subject_key, $term_id, $title];
	}

	/**
	 * Создаёт пост задания и привязывает его к таксономии.
	 *
	 * @param string   $subject_key Ключ предмета
	 * @param string   $taxonomy    Имя таксономии
	 * @param int      $term_id     ID термина
	 * @param \WP_Term $term        Объект термина
	 * @param string   $term_slug   Слаг термина
	 * @param string   $title       Заголовок задания
	 * @param string   $task_text   Текст задания (boilerplate)
	 *
	 * @return int|\WP_Error ID созданного поста или объект ошибки
	 */
	private function insertTaskPost(
		string $subject_key,
		string $taxonomy,
		int $term_id,
		\WP_Term $term,
		string $term_slug,
		string $title,
		string $task_text
	) {
		// Генерация числового префикса из слага термина
		$type_prefix = $this->extractNumberFromSlug($term_slug) ?: $term->term_id;

		// Подсчёт существующих заданий этого типа
		$current_count = $this->getExistingTasksCount($subject_key, $taxonomy, $term_id);

		// Генерация уникального slug: префикс + трёхзначный номер
		$custom_slug = $type_prefix . str_pad((string) $current_count, 3, '0', STR_PAD_LEFT);

		// Создание поста
		$new_id = wp_insert_post([
			'post_title'   => $title,
			'post_name'    => $custom_slug,
			'post_type'    => "{$subject_key}_tasks",
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
			'post_content' => $this->resolveDisplayContent($task_text),
		], true);

		// Привязка к таксономии (если пост создан успешно)
		if (!is_wp_error($new_id)) {
			wp_set_object_terms($new_id, $term_id, $taxonomy);
		}

		return $new_id;
	}

	/**
	 * Сохраняет мета-данные созданного поста: шаблон и boilerplate.
	 *
	 * @param int    $new_id      ID созданного поста
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина
	 * @param string $task_text   Текст задания (boilerplate)
	 *
	 * @return void
	 */
	private function applyPostMeta(
		int $new_id,
		string $subject_key,
		string $term_slug,
		string $task_text
	): void {
		// Получение привязки шаблона для данного типа задания
		$assignment = $this->metaboxes->getAssignment($subject_key, $term_slug);

		// Сохранение ID шаблона в мета-поле поста
		update_post_meta(
			$new_id,
			'_fs_lms_template_type',
			$assignment->template_id ?? TemplateManagerCallbacks::DEFAULT_TEMPLATE
		);

		// Сохранение boilerplate-текста (если есть)
		if (empty($task_text)) {
			return;
		}

		update_post_meta($new_id, 'fs_lms_meta', $this->buildMetaFromBoilerplate($task_text));
		clean_post_cache($new_id);
	}

	/**
	 * Парсит boilerplate-текст (JSON или строку) в массив для fs_lms_meta.
	 *
	 * @param string $task_text Текст задания (boilerplate)
	 *
	 * @return array<string, string> Массив мета-данных
	 */
	private function buildMetaFromBoilerplate(string $task_text): array
	{
		$clean   = wp_unslash($task_text);
		$decoded = json_decode($clean, true);

		// Если текст — JSON-массив, используем его как основу
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			return $decoded + ['task_answer' => ''];
		}

		// Иначе — обычный текст
		return [
			'task_condition' => $clean,
			'task_answer'    => '',
		];
	}

	/**
	 * Формирует строку для post_content редактора из boilerplate.
	 * JSON-массив склеивается через двойной перенос строки.
	 *
	 * @param string $task_text Текст задания (boilerplate)
	 *
	 * @return string Контент для post_content
	 */
	private function resolveDisplayContent(string $task_text): string
	{
		if (empty($task_text)) return '';

		$clean = wp_unslash($task_text);
		$decoded = json_decode($clean, true);

		// Если это сложный шаблон из нескольких полей (19-21 задачи)
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			// Склеиваем все части условия в один текст для редактора
			return implode("\n\n", $decoded);
		}

		// Если это обычная строка (старый формат или простое условие)
		return $clean;
	}

	/**
	 * Извлекает числовой суффикс из слага термина.
	 * Пример: 'inf_5' → 5, 'task' → 0.
	 *
	 * @param string $slug Слаг термина
	 *
	 * @return int Числовой суффикс или 0
	 */
	private function extractNumberFromSlug(string $slug): int
	{
		return preg_match('/(\d+)$/', $slug, $matches) ? (int) $matches[1] : 0;
	}

	/**
	 * Считает количество существующих заданий данного типа.
	 * Используется для генерации числового суффикса в slug.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $taxonomy    Имя таксономии
	 * @param int    $term_id     ID термина
	 *
	 * @return int Количество заданий
	 */
	private function getExistingTasksCount(string $subject_key, string $taxonomy, int $term_id): int
	{
		$query = new \WP_Query([
			'post_type'      => "{$subject_key}_tasks",
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'tax_query'      => [[
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_id,
			]],
		]);

		return (int) $query->found_posts;
	}
}