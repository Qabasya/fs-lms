<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;
use Inc\Repositories\SubjectRepository;
use Inc\Registrars\PluginRegistrar;

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
	 * Композитный регистратор плагина.
	 *
	 * @var PluginRegistrar
	 */
	private PluginRegistrar $registrar;

	/**
	 * Конструктор.
	 *
	 * Инициализирует репозиторий, регистратор и регистрирует все шаблоны метабоксов.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param PluginRegistrar   $registrar Композитный регистратор
	 */
	public function __construct(SubjectRepository $subjects, PluginRegistrar $registrar)
	{
		parent::__construct();

		$this->subjects  = $subjects;
		$this->registrar = $registrar;

		// Регистрируем все доступные шаблоны
		$this->registerTemplates();
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
	 * 1. Получение всех предметов
	 * 2. Определение шаблона по умолчанию (standard_task или первый в списке)
	 * 3. Для каждого предмета регистрация метабокса через регистратор
	 * 4. Подключение хуков: сохранение поста, фильтр списка шаблонов
	 *
	 * @return void
	 */
	public function register(): void
	{
		// Получаем все предметы
		$all_subjects = $this->subjects->read_all();
		if (empty($all_subjects)) {
			return;
		}

		// Определяем шаблон по умолчанию
		$default_template = $this->templates['standard_task'] ?? reset($this->templates);

		if (!$default_template) {
			error_log('FS LMS: Default template not found. Metaboxes will not work.');
			return;
		}

		// Регистрируем метабокс для каждого предмета
		foreach ($all_subjects as $key => $data) {
			$task_cpt = "{$key}_tasks";

			$this->registrar->metabox()
			                ->addTemplateBox(
				                $default_template,           // Шаблон по умолчанию
				                $task_cpt,                   // Тип поста (CPT заданий)
				                [$this, 'renderMetaboxContent'] // Коллбек отрисовки
			                );
		}

		// Выполняем регистрацию всех накопленных метабоксов
		$this->registrar->metabox()->register();

		// Подключаем обработчик сохранения мета-данных
		add_action('save_post', [$this, 'handleMetaSave']);

		// Регистрируем фильтр для получения списка шаблонов
		add_filter('fs_lms_get_templates', [$this, 'get_templates_list']);
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
	public function renderMetaboxContent($post, $callback_args): void
	{
		// Получаем тип шаблона из мета-поля (устанавливается при создании задания)
		$template_id = get_post_meta($post->ID, '_fs_lms_template_type', true) ?: 'standard_task';

		// Находим объект шаблона в зарегистрированном списке
		$template = $this->templates[$template_id]
		            ?? $this->templates['standard_task']
		               ?? reset($this->templates);

		// Если шаблон не найден — выводим сообщение об ошибке
		if (!$template) {
			echo '<p style="color:red;">Ошибка: Шаблон не найден. Проверьте регистрацию шаблонов в MetaBoxController.</p>';
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
		// Проверка nonce
		if (!isset($_POST['fs_lms_meta_nonce'])
		    || !wp_verify_nonce($_POST['fs_lms_meta_nonce'], 'fs_lms_save_meta')) {
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

		// Определяем тип шаблона для выбора полей санитизации
		$template_id = get_post_meta($post_id, '_fs_lms_template_type', true) ?: 'standard_task';
		$template    = $this->templates[$template_id] ?? null;

		// Если шаблон не найден или не имеет метода get_fields — выходим
		if (!$template || !method_exists($template, 'get_fields')) {
			return;
		}

		// Получаем список полей из шаблона
		$fields = $template->get_fields();

		// Получаем сырые данные из POST
		$raw_data = $_POST['fs_lms_meta'] ?? [];

		// Санитизация каждого поля в соответствии с его типом
		$sanitized = [];
		foreach ($fields as $id => $config) {
			if (isset($raw_data[$id]) && isset($config['object'])) {
				$sanitized[$id] = $config['object']->sanitize($raw_data[$id]);
			}
		}

		// Сохраняем очищенные мета-данные
		update_post_meta($post_id, 'fs_lms_meta', $sanitized);
	}

	/**
	 * Возвращает ассоциативный массив [id => name] всех зарегистрированных шаблонов.
	 *
	 * Используется в фильтре fs_lms_get_templates для получения списка
	 * доступных шаблонов в других частях плагина (например, в SubjectController).
	 *
	 * @return array<string, string> Массив шаблонов [template_id => template_name]
	 */
	public function get_templates_list(): array
	{
		$list = [];
		foreach ($this->templates as $id => $obj) {
			$list[$id] = $obj->get_name();
		}

		return $list;
	}
}