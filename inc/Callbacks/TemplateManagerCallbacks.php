<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\TaskTypeRepository;

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
class TemplateManagerCallbacks extends BaseController
{
	/**
	 * Маппинг ID шаблона → FQCN класса.
	 * При добавлении нового шаблона — только сюда, методы не трогать.
	 *
	 * @var array<string, class-string>
	 */
	private const TEMPLATES_MAP = [
		'standard_task'        => \Inc\MetaBoxes\Templates\StandardTaskTemplate::class,
		'triple_task'          => \Inc\MetaBoxes\Templates\ThreeInOneTemplate::class,
		'common_standard_task' => \Inc\MetaBoxes\Templates\CommonConditionTemplate::class,
	];

	/**
	 * ID шаблона по умолчанию.
	 * Публичная — используется в TaskCreationCallbacks при назначении шаблона новому посту.
	 *
	 * @var string
	 */
	public const DEFAULT_TEMPLATE = 'standard_task';

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
		parent::__construct();
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Обновляет привязку шаблона к конкретному типу задания (термину таксономии).
	 *
	 * Получает term_id из POST, находит термин в WordPress, вычисляет subject_key
	 * и сохраняет привязку через репозиторий. Использует тот же nonce, что и
	 * остальные операции с предметами (fs_subject_nonce), потому что вызывается
	 * из того же интерфейса.
	 *
	 * Переехало из SubjectSettingsCallbacks — логически принадлежит управлению шаблонами.
	 *
	 * @return void
	 */
	public function ajaxUpdateTaskTemplate(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_subject_nonce', 'security');

		// Проверка прав доступа (только администраторы)
		if (!current_user_can(self::ADMIN_CAPABILITY)) {
			wp_send_json_error('Нет прав', 403);
			return;
		}

		// Получение и валидация данных
		$term_id     = absint($_POST['term_id'] ?? 0);
		$template_id = sanitize_text_field(wp_unslash($_POST['template'] ?? ''));

		if (!$term_id || !$template_id) {
			wp_send_json_error('Недостаточно данных для обновления');
			return;
		}

		// Получение объекта термина
		$term = get_term($term_id);

		if (!$term || is_wp_error($term)) {
			wp_send_json_error('Тип задания не найден в WordPress');
			return;
		}

		// Вычисляем subject_key из slug таксономии: "phys_task_number" → "phys"
		$subject_key = str_replace('_task_number', '', $term->taxonomy);

		// Сохранение привязки через репозиторий
		$success = $this->metaboxes->updateAssignment(
			$subject_key,
			(string) $term->slug,
			$template_id
		);

		if (!$success) {
			wp_send_json_error('Ошибка сохранения шаблона');
			return;
		}

		wp_send_json_success("Шаблон для задания №{$term->slug} успешно сохранён!");
	}

	/**
	 * Сохраняет привязку шаблона к типу задания в Менеджере заданий.
	 *
	 * @return void
	 */
	public function ajaxSaveTemplateAssignment(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_lms_manager_nonce', 'nonce');

		// Проверка прав доступа (управление настройками)
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Доступ запрещён', 403);
			return;
		}

		// Получение и валидация данных
		$subject_key = sanitize_text_field(wp_unslash($_POST['subject_key'] ?? ''));
		$task_number = sanitize_text_field(wp_unslash($_POST['task_number'] ?? ''));
		$template_id = sanitize_text_field(wp_unslash($_POST['template_id'] ?? ''));

		if (!$this->hasRequiredKeys($subject_key, $task_number)) {
			wp_send_json_error('Некорректные данные');
			return;
		}

		// Сохранение привязки через репозиторий
		$this->metaboxes->updateAssignment($subject_key, $task_number, $template_id);

		wp_send_json_success(['message' => 'Настройки сохранены']);
	}

	/**
	 * Возвращает структуру ConditionField-полей шаблона для конкретного типа задания.
	 * Используется на фронте для построения редактора boilerplate.
	 *
	 * @return void
	 */
	public function ajaxGetTemplateStructure(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_lms_manager_nonce', 'nonce');

		// Проверка прав доступа (управление настройками)
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Доступ запрещён', 403);
			return;
		}

		// Получение данных из GET-запроса
		$subject_key = sanitize_text_field(wp_unslash($_GET['subject_key'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_GET['term_slug'] ?? ''));

		// Определение ID шаблона
		$assignment  = $this->metaboxes->getAssignment($subject_key, $term_slug);
		$template_id = $assignment->template_id ?? self::DEFAULT_TEMPLATE;
		$class_name  = self::TEMPLATES_MAP[$template_id] ?? self::TEMPLATES_MAP[self::DEFAULT_TEMPLATE];

		try {
			/** @var \Inc\MetaBoxes\Templates\BaseTemplate $template_obj */
			$template_obj = new $class_name();

			// Фильтрация полей: оставляем только ConditionField
			$fields = array_filter(
				$template_obj->get_fields(),
				static fn($config) => isset($config['object'])
				                      && $config['object'] instanceof \Inc\MetaBoxes\Fields\ConditionField
			);

			// Преобразование структуры полей для передачи на клиент
			$structure = array_values(array_map(
				function (string $key, array $config): array {
					// Генерация уникального ID для TinyMCE редактора
					$editor_id = 'tinymce_' . strtolower(preg_replace('/[^a-z0-9_]/i', '_', $key));

					return [
						'id'    => $key,
						'label' => $config['label'],
						'html'  => sprintf(
							'<div class="fs-lms-boilerplate-editor-container">
                                <textarea id="%s" class="js-boilerplate-editor" data-field-key="%s" rows="10" style="width:100%%"></textarea>
                            </div>',
							esc_attr($editor_id),
							esc_attr($key)
						),
					];
				},
				array_keys($fields),
				$fields
			));

			wp_send_json_success(['fields' => $structure]);
		} catch (\Throwable $e) {
			wp_send_json_error('Ошибка PHP: ' . $e->getMessage());
		}
	}

	/**
	 * Сохраняет boilerplate-текст для типа задания.
	 *
	 * @return void
	 */
	public function ajaxSaveBoilerplate(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer( 'fs_lms_manager_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Доступ запрещён', 403 );
			return;
		}

		$subject_key = sanitize_text_field( wp_unslash( $_POST['subject_key'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_POST['term_slug'] ?? '' ) );

		// ВАЖНО: wp_unslash здесь обязателен для корректного хранения JSON
		$text = wp_unslash( $_POST['text'] ?? '' );

		if ( empty( $subject_key ) || empty( $term_slug ) ) {
			wp_send_json_error( 'Недостаточно данных' );
			return;
		}

		// Репозиторий должен просто обновить запись в таблице
		$this->taskTypes->update( [
			'subject_key' => $subject_key,
			'term_slug'   => $term_slug,
			'text'        => $text,
		] );

		wp_send_json_success( [ 'message' => 'Типовое условие сохранено' ] );
	}

	/**
	 * Возвращает boilerplate-текст для типа задания.
	 *
	 * @return void
	 */
	public function ajaxGetBoilerplate(): void
	{
		// Проверка nonce для защиты от CSRF
		check_ajax_referer('fs_lms_manager_nonce', 'nonce');

		// Проверка прав доступа (управление настройками)
		if (!current_user_can('manage_options')) {
			wp_send_json_error('У вас недостаточно прав', 403);
			return;
		}

		// Получение данных из GET-запроса
		$subject_key = sanitize_text_field(wp_unslash($_GET['subject_key'] ?? ''));
		$term_slug   = sanitize_text_field(wp_unslash($_GET['term_slug'] ?? ''));

		$text = wp_unslash( $_POST['text'] ?? '' );

		if (!$this->hasRequiredKeys($subject_key, $term_slug)) {
			wp_send_json_error('Недостаточно данных');
			return;
		}

		// Получение данных из репозитория
		$boilerplate = $this->taskTypes->getBoilerplate($subject_key, $term_slug);

		wp_send_json_success([
			'text' => $boilerplate->text ?? '',
		]);
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет, что оба обязательных ключа не пустые.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина
	 *
	 * @return bool true, если оба ключа не пустые
	 */
	private function hasRequiredKeys(string $subject_key, string $term_slug): bool
	{
		return !empty($subject_key) && !empty($term_slug);
	}
}