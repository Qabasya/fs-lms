<?php
declare(strict_types=1);

namespace Inc\Enums;

/**
 * Имена опций WordPress для хранения данных плагина.
 *
 * @package Inc\Core\Config
 */
enum OptionName: string
{
	/** Опция для хранения списка предметов. */
	case SUBJECTS = 'fs_lms_subjects_list';

	/** Опция для хранения привязок заданий к шаблонам метабоксов. */
	case METABOXES = 'fs_lms_custom_metaboxes';

	/** Опция для хранения пользовательских таксономий. */
	case TAXONOMY = 'fs_lms_custom_taxonomies';

	/** Опция для хранения типовых условий (boilerplate) заданий. */
	case BOILERPLATE = 'fs_lms_task_type_boilerplates';
}
