<?php

namespace Inc\Core;

/**
 * Class BaseController
 *
 * Базовый контроллер с общей конфигурацией и утилитами.
 *
 * Содержит константы для всех критических идентификаторов плагина:
 * - Slugs страниц меню
 * - Capabilities (права доступа)
 * - Настройки (option groups, option names)
 * - Пути к файлам
 *
 * Также предоставляет удобные методы для получения путей и URL
 * внутри плагина.
 *
 * @package Inc\Core
 */
class BaseController
{
	// ===== СЛАГИ =====//

	/**
	 * Slug главного меню плагина.
	 *
	 * @var string
	 */
	public const MAIN_MENU_SLUG = 'fs_lms';

	/**
	 * Slug меню предметов.
	 *
	 * @var string
	 */
	public const SUBJECTS_MENU_SLUG = 'fs_subjects';

	// ===== CAPABILITY =====//

	/**
	 * Capability (право доступа) для административных страниц.
	 *
	 * @var string
	 */
	public const ADMIN_CAPABILITY = 'manage_options';

	// ===== OPTION NAMES =====//

	/**
	 * Имя опции для хранения списка предметов.
	 *
	 * @var string
	 */
	public const SUBJECTS_OPTION_NAME = 'fs_lms_subjects_list';

	/**
	 * Имя опции для хранения кастомных метабоксов.
	 *
	 * @var string
	 */
	public const TAXONOMY_OPTION_NAME = 'fs_lms_custom_metaboxes';

	/**
	 * Имя опции для хранения кастомных таксономий.
	 *
	 * @var string
	 */
	public const METABOXES_OPTION_NAME = 'fs_lms_custom_taxonomies';

	/**
	 * Имя опции для хранения типовых условий (boilerplate) заданий.
	 *
	 * @var string
	 */
	public const BOILERPLATE_OPTION_NAME = 'fs_lms_task_type_boilerplates';

	// ===== ВАЛИДАЦИЯ =====//

	/**
	 * Минимальное количество заданий в предмете.
	 *
	 * @var int
	 */
	public const MIN_TASKS_COUNT = 1;

	/**
	 * Максимальное количество заданий в предмете.
	 *
	 * @var int
	 */
	public const MAX_TASKS_COUNT = 100;

	// ===== НАЗВАНИЯ МЕНЮ =====//

	/**
	 * Заголовок первой страницы меню.
	 *
	 * @var string
	 */
	public const FIRST_PAGE_TITLE = 'Dashboard';

	/**
	 * Название первого пункта меню.
	 *
	 * @var string
	 */
	public const FIRST_MENU_TITLE = 'Статистика';

	/**
	 * Заголовок второй страницы меню.
	 *
	 * @var string
	 */
	public const SECOND_PAGE_TITLE = 'Настройки';

	/**
	 * Название второго пункта меню.
	 *
	 * @var string
	 */
	public const SECOND_MENU_TITLE = 'Настройки плагина';

	/**
	 * Абсолютный путь к директории плагина.
	 *
	 * @var string
	 */
	public string $plugin_path;

	/**
	 * URL директории плагина.
	 *
	 * @var string
	 */
	public string $plugin_url;

	/**
	 * Базовое имя плагина (plugin_basename).
	 *
	 * @var string
	 */
	public string $plugin_name;

	/**
	 * Версия плагина.
	 *
	 * @var string
	 */
	public string $plugin_version = '0.0.1';

	/**
	 * Конструктор.
	 *
	 * Инициализирует пути и URL плагина.
	 * Вызывается автоматически при создании экземпляра через DI-контейнер.
	 */
	public function __construct()
	{
		// Путь в корень плагина (на уровень выше папки Core)
		$root_path = dirname(__FILE__, 2);

		// Устанавливаем абсолютный путь к папке плагина
		$this->plugin_path = plugin_dir_path($root_path);

		// Устанавливаем URL папки плагина
		$this->plugin_url = plugin_dir_url($root_path);

		// Устанавливаем базовое имя плагина (например, "fs-lms/fs-lms.php")
		$this->plugin_name = plugin_basename($root_path);
	}

	/**
	 * Возвращает полный путь к файлу внутри плагина.
	 *
	 * @param string $path Относительный путь (например, 'templates/admin.php')
	 *
	 * @return string Абсолютный путь к файлу
	 *
	 * @example
	 * $this->path('templates/admin.php');
	 * // Результат: /var/www/wp-content/plugins/fs-lms/templates/admin.php
	 */
	public function path(string $path = ''): string
	{
		return $this->plugin_path . ltrim($path, '/\\');
	}

	/**
	 * Возвращает URL к файлу внутри плагина.
	 *
	 * @param string $path Относительный путь (например, 'assets/css/style.css')
	 *
	 * @return string URL к файлу
	 *
	 * @example
	 * $this->url('assets/css/style.css');
	 * // Результат: https://example.com/wp-content/plugins/fs-lms/assets/css/style.css
	 */
	public function url(string $path = ''): string
	{
		return $this->plugin_url . ltrim($path, '/');
	}

	/**
	 * Возвращает базовое имя плагина.
	 *
	 * @return string Базовое имя плагина (fs-lms/fs-lms.php)
	 */
	public function pluginName(): string
	{
		return $this->plugin_name;
	}
}