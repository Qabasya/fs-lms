<?php
declare(strict_types=1);

namespace Inc\Enums;

/**
 * Capability для административного доступа.
 *
 * @package Inc\Core\Config
 */
enum Capability: string {

	/** Capability для административных страниц. */
	case ADMIN = 'manage_options';

	/** Capability для своих преподавателей */
	case ViewLMSStats         = 'view_lms_stats';
	case ManageLMSAssignments = 'manage_lms_assignments';
}
