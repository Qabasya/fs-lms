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
	case Subjects = 'fs_lms_subjects_list';

	/** Опция для хранения привязок заданий к шаблонам метабоксов. */
	case Metaboxes = 'fs_lms_custom_metaboxes';

	/** Опция для хранения пользовательских таксономий. */
	case Taxonomy = 'fs_lms_custom_taxonomies';

	/** Опция для хранения типовых условий (boilerplate) заданий. */
	case Boilerplate = 'fs_lms_task_type_boilerplates';

	/** Опция для хранения настроек аутентификации (API ключи соцсетей). */
	case AuthSettings = 'fs_lms_auth_settings';

	/** Опция для хранения учебных периодов. */
	case AcademicPeriods = 'fs_lms_academic_periods';

	/** Опция для хранения групп студентов. */
	case StudentGroups = 'fs_lms_student_groups';

	/** Опция для хранения мета-данных привязки студентов к периодам. */
	case StudentPeriodMeta = 'fs_lms_student_period_meta';

	/** Версия схемы БД */
	case SchemaVersion = 'fs_lms_schema_version';

	/** Справочник учебных периодов */
	case Periods = 'fs_lms_periods_list';

	/** Мета согласия: текущий sha256-хэш и дата обновления WP-страницы */
	case ConsentPageMeta = 'fs_lms_consent_page_meta';

	/** Шаблоны email-писем (редактируются в настройках плагина) */
	case EmailTemplates = 'fs_lms_email_templates';

	/** Определения согласий: [key => ['name' => string, 'page_id' => int]] */
	case ConsentDefinitions = 'fs_lms_consent_definitions';

	/** Option group */
	case AuthGroups = 'fs_lms_auth_group';

	/** Конфигурация плагина: DaData-токен, тест-окружение, OTP bypass-код */
	case PluginConfig = 'fs_lms_plugin_config';
}
