<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Capability;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\TaskTypeRepository;
use Inc\Enums\TaskTemplate;

/**
 * Class TemplateManagerCallbacks
 *
 * AJAX-обработчики для Менеджера заданий:
 * - привязка шаблонов к типам заданий
 * - обновление шаблона конкретного термина (переехало из SubjectSettingsCallbacks)
 * - получение структуры полей шаблона
 * - сохранение и получение boilerplate-текста
 *
 * @package Inc\Callbacks
 */
class TemplateManagerCallbacks
{
	/**
	 * Nonce для операций менеджера заданий.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'fs_lms_manager_nonce';

	/**
	 * Ключ nonce в запросе.
	 *
	 * @var string
	 */
	private const NONCE_KEY = 'nonce';

	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository  $metaboxes Репозиторий привязок шаблонов
	 * @param TaskTypeRepository $taskTypes Репозиторий типов заданий
	 */
	public function __construct(
		private MetaBoxRepository $metaboxes,
		private TaskTypeRepository $taskTypes,
	) {
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Обновляет привязку шаблона к конкретному типу задания (термину таксономии).
	 *
	 * Использует nonce fs_subject_nonce — вызывается из интерфейса управления предметами,
	 * поэтому имеет собственную авторизацию вместо общего authorize().
	 *
	 * @return void
	 */
	public function ajaxUpdateTermTemplate(): void
	{
		// Проверка nonce для защиты от CSRF (используется nonce предметов)
		check_ajax_referer('fs_subject_nonce', 'security');

		// Проверка прав доступа (только администраторы)
		if (!current_user_can(Capability::ADMIN->value)) {
			wp_send_json_error('Нет прав', 403);
		}

		// Получение и валидация данных
		$term_id     = absint($_POST['term_id'] ?? 0);
		$template_id = sanitize_text_field(wp_unslash($_POST['template'] ?? ''));

		if (!$term_id || !$template_id) {
			wp_send_json_error('Недостаточно данных для обновления');
		}

		// Получение объекта термина
		$term = get_term($term_id);

		if (!$term || is_wp_error($term)) {
			wp_send_json_error('Тип задания не найден в WordPress');
		}

		// Извлечение ключа предмета из таксономии: "phys_task_number" → "phys"
		$subject_key = str_replace('_task_number', '', $term->taxonomy);

		// Сохранение привязки через репозиторий
		$success = $this->metaboxes->updateAssignment(
			$subject_key,
			(string) $term->slug,
			$template_id
		);

		if (!$success) {
			wp_send_json_error('Ошибка сохранения шаблона');
		}

		wp_send_json_success("Шаблон для задания №{$term->slug} успешно сохранён!");
	}

	/**
	 * Возвращает структуру ConditionField-полей шаблона для конкретного типа задания.
	 *
	 * Используется на фронте для построения редактора boilerplate.
	 * Отдаёт только данные (id, label) — HTML строит JS на стороне клиента.
	 *
	 * @return void
	 */
	public function ajaxGetTemplateStructure(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных из GET-запроса
		$subject_key = sanitize_text_field(wp_unslash($_GET['subject_key'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_GET['term_slug'] ?? ''));

		if (!$subject_key || !$term_slug) {
			wp_send_json_error('Недостаточно данных');
		}

		// Получаем объект привязки из БД
		$assignment = $this->metaboxes->getAssignment($subject_key, $term_slug);

		// Определяем шаблон через Enum (это и есть наш "источник правды")
		$template = TaskTemplate::tryFrom($assignment->template_id ?? '')
		            ?? TaskTemplate::STANDARD;

		// Получаем имя класса из Enum
		$class_name = $template->class();

		try {
			// Прямое создание объекта (вместо поиска в фильтрах)
			if (!class_exists($class_name)) {
				throw new \Exception("Класс шаблона {$class_name} не найден.");
			}

			/** @var \Inc\MetaBoxes\Templates\BaseTemplate $template_obj */
			$template_obj = new $class_name();

			// Вызываем метод get_fields() прямо у объекта шаблона
			$all_fields = $template_obj->get_fields();

			// Фильтрация: оставляем только ConditionField-поля
			$condition_fields = array_filter(
				$all_fields,
				static fn($config) => isset($config['object'])
				                      && $config['object'] instanceof ConditionField
			);

			// Отдаём только данные (id, label)
			$structure = array_values(array_map(
				static fn(string $key, array $config): array => [
					'id'    => $key,
					'label' => $config['label'],
				],
				array_keys($condition_fields),
				$condition_fields
			));

			wp_send_json_success(['fields' => $structure]);
		} catch (\Throwable $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('FS LMS TemplateManagerCallbacks: ' . $e->getMessage());
			}
			wp_send_json_error('Ошибка загрузки структуры: ' . $e->getMessage());
		}
	}

	/**
	 * Сохраняет boilerplate-текст для типа задания.
	 *
	 * Использует фиксированный uid 'default' — этот метод работает
	 * в режиме "один boilerplate на тип задания" (legacy Менеджер заданий).
	 * Полноценный CRUD с несколькими вариантами — в BoilerplateCallbacks.
	 *
	 * @return void
	 */
	public function ajaxSaveTaskBoilerplate(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных
		$subject_key = sanitize_text_field(wp_unslash($_POST['subject_key'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_POST['term_slug'] ?? ''));
		// wp_unslash обязателен для корректного хранения HTML/JSON из редактора
		$text = wp_kses_post(wp_unslash($_POST['text'] ?? ''));

		if (!$subject_key || !$term_slug) {
			wp_send_json_error('Недостаточно данных');
		}

		// Фиксированный uid гарантирует обновление, а не создание нового варианта
		$dto = new TaskTypeBoilerplateDTO(
			uid: 'default',
			subject_key: $subject_key,
			term_slug: $term_slug,
			title: 'Типовое условие',
			content: $text,
			is_default: true
		);

		// Сохранение через репозиторий
		$success = $this->taskTypes->updateBoilerplate($dto);

		if (!$success) {
			wp_send_json_error('Ошибка сохранения типового условия');
		}

		wp_send_json_success(['message' => 'Типовое условие сохранено']);
	}

	/**
	 * Возвращает дефолтный boilerplate для типа задания.
	 *
	 * @return void
	 */
	public function ajaxGetBoilerplate(): void
	{
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация данных из GET-запроса
		$subject_key = sanitize_text_field(wp_unslash($_GET['subject_key'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_GET['term_slug'] ?? ''));

		if (!$subject_key || !$term_slug) {
			wp_send_json_error('Недостаточно данных');
		}

		// Репозиторий сам знает, как найти дефолтный вариант
		$result = $this->taskTypes->getDefaultBoilerplate($subject_key, $term_slug);

		wp_send_json_success([
			'text' => $result?->content ?? '',
			'uid'  => $result?->uid ?? null,
		]);
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет nonce и права администратора.
	 * Завершает выполнение через wp_send_json_error при неудаче.
	 *
	 * @return void
	 */
	private function authorize(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer(self::NONCE_ACTION, self::NONCE_KEY);

		// Проверка прав доступа (только администраторы)
		if (!current_user_can(Capability::ADMIN->value)) {
			wp_send_json_error('Доступ запрещён', 403);
		}
	}
}