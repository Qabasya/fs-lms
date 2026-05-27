<?php
declare(strict_types=1);

namespace Inc\Enums;

/**
 * Capability для административного доступа.
 *
 * @package Inc\Core\Config
 */
enum Capability: string {

// ===== Администрирование =====

	/** Базовое право администратора WordPress для доступа к настройкам */
	case Admin = 'manage_options';

	// ===== Преподавательские права =====

	/** Право просмотра статистики LMS */
	case ViewLMSStats = 'view_lms_stats';

	/** Право управления заданиями (создание, редактирование, удаление) */
	case ManageLMSAssignments = 'manage_lms_assignments';

	// ===== Управление заявками =====

	/** Право управления заявками на обучение */
	case ManageApplications = 'manage_applications';

	/** Право зачисления студентов */
	case EnrollStudent = 'enroll_student';

	// ===== PII (Персональные данные) =====

	/** Право просмотра персональных данных */
	case ViewPII = 'view_pii';

	/** Право экспорта персональных данных */
	case ExportPII = 'export_pii';

	/** Право управления данными о людях (создание, редактирование, удаление) */
	case ManagePersons = 'manage_persons';
}
