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

	/** Capability для события зачисления */
	case ManageApplications = 'manage_applications';
	case EnrollStudents     = 'enroll_students';
	case ViewPII            = 'view_pii';
	case ExportPII          = 'export_pii';
	case ManagePersons      = 'manage_persons';
}
