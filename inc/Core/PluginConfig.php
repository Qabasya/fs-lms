<?php

declare(strict_types=1);

namespace Inc\Core;

/**
 * Class PluginConfig
 *
 * Центральное хранилище всех констант плагина.
 * Не содержит логики — только конфигурационные значения.
 *
 * BaseController наследует этот класс, поэтому все константы
 * доступны через self:: в контроллерах и коллбеках.
 * Репозитории и другие классы, не связанные с WP-инфраструктурой,
 * ссылаются на константы напрямую: PluginConfig::CONSTANT_NAME.
 *
 * @package Inc\Core
 */
class PluginConfig
{
	// ===== СЛАГИ =====

	/** Slug главного меню плагина. */
	public const MAIN_MENU_SLUG = 'fs_lms';

	/** Slug меню предметов. */
	public const SUBJECTS_MENU_SLUG = 'fs_subjects';

	// ===== CAPABILITY =====

	/** Capability для административных страниц. */
	public const ADMIN_CAPABILITY = 'manage_options';

	// ===== OPTION NAMES =====

	/** Опция для хранения списка предметов. */
	public const SUBJECTS_OPTION_NAME = 'fs_lms_subjects_list';

	/** Опция для хранения привязок заданий к шаблонам метабоксов. */
	public const METABOXES_OPTION_NAME = 'fs_lms_custom_metaboxes';

	/** Опция для хранения пользовательских таксономий. */
	public const TAXONOMY_OPTION_NAME = 'fs_lms_custom_taxonomies';

	/** Опция для хранения типовых условий (boilerplate) заданий. */
	public const BOILERPLATE_OPTION_NAME = 'fs_lms_task_type_boilerplates';

	// ===== ВАЛИДАЦИЯ =====

	/** Минимальное количество заданий в предмете. */
	public const MIN_TASKS_COUNT = 1;

	/** Максимальное количество заданий в предмете. */
	public const MAX_TASKS_COUNT = 100;

	// ===== НАЗВАНИЯ МЕНЮ =====

	public const FIRST_PAGE_TITLE  = 'Dashboard';
	public const FIRST_MENU_TITLE  = 'Статистика';
	public const SECOND_PAGE_TITLE = 'Настройки';
	public const SECOND_MENU_TITLE = 'Настройки плагина';
}