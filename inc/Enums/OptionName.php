<?php
declare(strict_types=1);

namespace Inc\Enums;

/**
 * Enum OptionName
 *
 * Имена опций WordPress для хранения данных плагина.
 *
 * @package Inc\Enums
 *
 * ### Основные обязанности:
 *
 * 1. **Централизованное хранение ключей опций** — все названия опций в одном месте.
 * 2. **Предотвращение дублирования строк** — единый источник истины для ключей.
 *
 * ### Архитектурная роль:
 *
 * Используется в репозиториях (SubjectsRepository, TaxonomyRepository, SettingsRepository и др.)
 * для получения имени опции при вызовах get_option(), update_option(), delete_option().
 */
enum OptionName: string {

	/** Опция для хранения списка предметов. */
	case SUBJECTS = 'fs_lms_subjects_list';

	/** Опция для хранения привязок заданий к шаблонам метабоксов. */
	case METABOXES = 'fs_lms_custom_metaboxes';

	/** Опция для хранения пользовательских таксономий. */
	case TAXONOMY = 'fs_lms_custom_taxonomies';

	/** Опция для хранения типовых условий (boilerplate) заданий. */
	case BOILERPLATE = 'fs_lms_task_type_boilerplates';

	/** Опция для хранения настроек аутентификации (API ключи соцсетей). */
	case AUTH_SETTINGS = 'fs_lms_auth_settings';

	/** Опция для хранения учебных годов. */
	case ACADEMIC_YEARS = 'fs_lms_academic_years';

	/** Опция для хранения групп студентов. */
	case STUDENT_GROUPS = 'fs_lms_student_groups';

	/** Опция для хранения мета-данных привязки студентов к годам. */
	case STUDENT_YEAR_META = 'fs_lms_student_year_meta';
}