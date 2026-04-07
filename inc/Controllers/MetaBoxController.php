<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;
use Inc\Repositories\SubjectRepository;
use Inc\Registrars\PluginRegistrar;
use Inc\Repositories\MetaBoxRepository;
use Inc\DTO\TaskMetaDTO;

/**
 * Class MetaBoxController
 *
 * Контроллер управления метабоксами заданий.
 * Отвечает за динамическую регистрацию шаблонов метабоксов,
 * их регистрацию для каждого предмета и обработку сохранения данных.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class MetaBoxController extends BaseController implements ServiceInterface
{
	/**
	 * Список доступных шаблонов метабоксов.
	 * Структура: [ 'template_id' => TemplateObject ]
	 *
	 * @var array<string, object>
	 */
	private array $templates = [];

	/**
	 * Репозиторий предметов.
	 *
	 * @var SubjectRepository
	 */
	private SubjectRepository $subjects;

	/**
	 * Репозиторий для работы с привязками заданий к шаблонам.
	 *
	 * @var MetaBoxRepository
	 */
	private MetaBoxRepository $metaboxes;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозитории, регистратор и регистрирует все шаблоны метабоксов.
	 *
	 * @param SubjectRepository $subjects  Репозиторий предметов
	 * @param PluginRegistrar   $registrar Композитный регистратор
	 * @param MetaBoxRepository $metaboxes Репозиторий привязок заданий к шаблонам
	 */
	public function __construct(
		SubjectRepository $subjects,
		PluginRegistrar $registrar,
		MetaBoxRepository $metaboxes
	) {
		parent::__construct();
		$this->subjects  = $subjects;
		$this->registrar = $registrar;
		$this->metaboxes = $metaboxes;
	}

	/**
	 * Регистрирует все шаблоны метабоксов.
	 *
	 * Создаёт экземпляры всех доступных шаблонов, проверяет их валидность
	 * и сохраняет в массив $templates.
	 *
	 * @return void
	 */
	private function registerTemplates(): void
	{
		// Список всех доступных шаблонов
		$templatesToRegister = [
			new CodeTaskTemplate(),
			new FileCodeTaskTemplate(),
			new FileTaskTemplate(),
			new StandardTaskTemplate(),
			new TwoFileCodeTaskTemplate(),
			new ThreeInOneTemplate(),
		];

		// Регистрируем каждый шаблон, прошедший валидацию
		foreach ($templatesToRegister as $template) {
			if ($this->isValidTemplate($template)) {
				$this->templates[$template->get_id()] = $template;
			} else {
				error_log('FS LMS: Invalid template: ' . get_class($template));
			}
		}

		// Логируем результат регистрации
		if (empty($this->templates)) {
			error_log('FS LMS: No templates were registered!');
		} else {
			error_log('FS LMS: Successfully registered templates: ' . implode(', ', array_keys($this->templates)));
		}
	}

	/**
	 * Проверяет, является ли объект валидным шаблоном (duck typing).
	 *
	 * Шаблон считается валидным, если имеет все необходимые методы:
	 * - get_id() — получение идентификатора
	 * - get_name() — получение названия
	 * - render() — отрисовка контента
	 * - get_fields() — получение списка полей
	 *
	 * @param object $template Объект для проверки
	 *
	 * @return bool true, если объект соответствует интерфейсу шаблона
	 */
	private function isValidTemplate(object $template): bool
	{
		return method_exists($template, 'get_id')
		       && method_exists($template, 'get_name')
		       && method_exists($template, 'render')
		       && method_exists($template, 'get_fields');
	}

	/**
	 * Точка входа в сервис (вызывается из Init.php).
	 *
	 * Регистрирует метабоксы для каждого предмета и подключает обработчик сохранения.
	 *
	 * Процесс регистрации:
	 * 1. Регистрация метабоксов через нативный хук add_meta_boxes
	 * 2. Для каждого предмета создаётся метабокс на CPT заданий
	 * 3. Подключение хуков: сохранение поста, фильтр списка шаблонов
	 *
	 * @return void
	 */
	public function register(): void
	{
		// add_meta_boxes срабатывает только на экранах редактирования —
		// никаких ручных проверок $pagenow не нужно

		// Регистрация метабоксов (сработает в редакторе)
		add_action('add_meta_boxes', function () {
			// Убеждаемся, что шаблоны загружены
			if (empty($this->templates)) {
				$this->registerTemplates();
			}

			$all_subjects = $this->subjects->readAll();

			if (empty($all_subjects)) {
				return;
			}

			// Для каждого предмета добавляем метабокс на CPT заданий
			foreach ($all_subjects as $subject) {
				$task_cpt = "{$subject->key}_tasks";

				add_meta_box(
					'fs_lms_task_metabox',           // Уникальный ID метабокса
					'Данные задания',                // Заголовок метабокса
					[$this, 'renderMetaboxContent'], // Коллбек для отрисовки
					$task_cpt,                       // Тип поста (CPT заданий)
					'normal',                        // Контекст отображения
					'high'                           // Приоритет
				);
			}
		});

		// save_post — вешаем всегда, но шаблоны регистрируем внутри,
		// только когда это реальный POST-запрос на сохранение
		add_action('save_post', [$this, 'handleMetaSave']);

		// Регистрация фильтра для получения списка шаблонов
		add_filter('fs_lms_get_templates', [$this, 'getTemplatesList']);
	}

	// ============================ КОЛЛБЕКИ И ОБРАБОТКА ============================ //

	/**
	 * Отрисовка контента метабокса.
	 *
	 * Коллбек, вызываемый WordPress при отображении метабокса.
	 * Определяет тип шаблона из мета-поля поста и рендерит соответствующий интерфейс.
	 *
	 * @param WP_Post $post          Текущий пост
	 * @param array   $callback_args Дополнительные аргументы из add_meta_box
	 *
	 * @return void
	 */
	public function renderMetaboxContent($post): void
	{
		// Определяем ID шаблона для текущего поста
		$template_id = $this->getTemplateId($post);

		// Находим объект шаблона в зарегистрированном списке
		$template = $this->templates[$template_id]
		            ?? $this->templates['standard_task']
		               ?? reset($this->templates);

		if (!$template) {
			echo 'Ошибка: Шаблон не найден.';
			return;
		}

		// Добавляем nonce-поле для защиты от CSRF
		wp_nonce_field('fs_lms_save_meta', 'fs_lms_meta_nonce');

		// Получаем текущие сохранённые значения мета-данных
		$values = get_post_meta($post->ID, 'fs_lms_meta', true) ?: [];

		// Рендерим контент метабокса
		echo '<div class="fs-lms-metabox-wrapper">';
		$template->render($post, $values);
		echo '</div>';
	}

	/**
	 * Обработка сохранения мета-данных поста.
	 *
	 * Вызывается при сохранении поста. Проверяет nonce, права доступа,
	 * и на основе типа шаблона санитизирует и сохраняет мета-поля.
	 *
	 * @param int $post_id ID сохраняемого поста
	 *
	 * @return void
	 */
	public function handleMetaSave(int $post_id): void
	{
		$post = get_post($post_id);

		// Проверяем, что пост существует и является заданием (оканчивается на "_tasks")
		if (!$post || !str_ends_with($post->post_type, '_tasks')) {
			return;
		}

		// Проверка nonce
		if (!isset($_POST['fs_lms_meta_nonce']) || !wp_verify_nonce($_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta')) {
			return;
		}

		// Пропускаем автосохранение
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Проверка прав текущего пользователя
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		// Шаблоны могут быть ещё не загружены (save_post без add_meta_boxes)
		if (empty($this->templates)) {
			$this->registerTemplates();
		}

		// Определяем шаблон точно так же, как при отрисовке
		$template_id = $this->getTemplateId($post);
		$template    = $this->templates[$template_id] ?? null;

		// Если шаблон не найден или не имеет метода get_fields — выходим
		if (!$template || !method_exists($template, 'get_fields')) {
			return;
		}

		// Санитизация и сохранение мета-данных
		$fields    = $template->get_fields();
		$raw_data  = $_POST['fs_lms_meta'] ?? [];
		$sanitized = [];

		foreach ($fields as $id => $config) {
			if (isset($raw_data[$id]) && isset($config['object'])) {
				$sanitized[$id] = $config['object']->sanitize($raw_data[$id]);
			}
		}

		update_post_meta($post_id, 'fs_lms_meta', $sanitized);
	}

	/**
	 * Возвращает список всех зарегистрированных шаблонов в виде объектов DTO.
	 *
	 * Используется в фильтре fs_lms_get_templates для передачи данных
	 * в контроллеры и шаблоны.
	 *
	 * @return TaskMetaDTO[] Массив объектов конфигурации шаблонов
	 */
	public function getTemplatesList(): array
	{
		// Если шаблоны ещё не загружены — загружаем
		if (empty($this->templates)) {
			$this->registerTemplates();
		}

		$list = [];
		foreach ($this->templates as $template) {
			$list[] = new TaskMetaDTO(
				id: $template->get_id(),
				title: $template->get_name(),
				fields: method_exists($template, 'get_fields') ? $template->get_fields() : []
			);
		}

		return $list;
	}

	/**
	 * Определяет ID шаблона для конкретного поста.
	 *
	 * Реализует иерархию выбора шаблона:
	 * 1. Приоритет: Глобальные настройки предмета (из MetaBoxRepository)
	 * 2. Приоритет: Мета-поле конкретного поста (_fs_lms_template_type)
	 * 3. Приоритет: Стандартный шаблон (standard_task)
	 *
	 * @param \WP_Post $post Объект поста
	 *
	 * @return string ID выбранного шаблона
	 */
	private function getTemplateId($post): string
	{
		// Извлекаем ключ предмета из post_type (например, "math_tasks" → "math")
		$subject_key = str_replace('_tasks', '', $post->post_type);
		$taxonomy    = "{$subject_key}_task_number";

		// Получаем номер задания (термин таксономии)
		$terms = wp_get_post_terms($post->ID, $taxonomy);

		// ПРИОРИТЕТ 1: Глобальные настройки предмета (репозиторий)
		if (!is_wp_error($terms) && !empty($terms)) {
			$task_slug  = (string) $terms[0]->slug;
			$assignment = $this->metaboxes->getAssignment($subject_key, $task_slug);

			if ($assignment) {
				return $assignment->template_id;
			}
		}

		// ПРИОРИТЕТ 2: Мета-поле конкретного поста (обратная совместимость)
		$saved_meta = get_post_meta($post->ID, '_fs_lms_template_type', true);
		if (!empty($saved_meta)) {
			return $saved_meta;
		}

		// ПРИОРИТЕТ 3: Стандартный шаблон по умолчанию
		return 'standard_task';
	}
}