<?php
declare(strict_types=1);

namespace Inc\Enums\Access;

/**
 * Capability для административного доступа.
 *
 * @package Inc\Core\Config
 */
enum Capability: string {

// ===== Администрирование =====

	/** Базовое право администратора WordPress для доступа к настройкам */
	case Admin = 'manage_options';

	/** Право управления платформой LMS (административные страницы плагина) */
	case ManageLmsPlatform = 'manage_lms_platform';

	/** Право назначения LMS-ролей пользователям (только administrator) */
	case ManageLmsRoles = 'manage_lms_roles';

	// ===== Авторинг и проведение =====

	/** Право авторинга: создание/редактирование курсов, уроков, работ, контрольных, задач */
	case AuthorLmsCourses = 'author_lms_courses';

	/** Право управления статьями LMS */
	case ManageLmsArticles = 'manage_lms_articles';

	/** Право проведения обучения (оценивание, журнал, расписание) */
	case ManageLmsTeaching = 'manage_lms_teaching';

	// ===== Статистика =====

	/** Право просмотра статистики LMS */
	case ViewLMSStats = 'view_lms_stats';

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
