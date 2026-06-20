<?php

namespace Inc\Enums\Access;

/**
 * Enum UserRole
 *
 * Перечисление ролей пользователей, используемых в плагине.
 *
 * @package Inc\Enums
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение идентификаторов ролей** — централизованное хранение названий ролей.
 * 2. **Отображение названий** — предоставление человекочитаемых меток для админки.
 *
 * ### Архитектурная роль:
 *
 * Используется в UserManager (создание ролей), UserRepository (фильтрация по роли)
 * и UserDTO (преобразование роли WordPress в enum).
 *
 * ### Группы ролей:
 *
 * - **Защищённые роли (FS)** — пользователи с подпиской/доступом к контенту
 * - **Свободные роли (Free)** — внешние пользователи без подписки
 */
enum UserRole: string {

	// === Защищённые роли (авторизация логин-пароль) ===

	/** Преподаватель с полным доступом к учебным материалам */
	case FSTeacher = 'lms_teacher';

	/** Ученик с доступом к учебным материалам */
	case FSStudent = 'lms_student';

	/** Родитель ученика (просмотр прогресса и управление детьми) */
	case FSParent = 'lms_parent';

	/** Администратор LMS (работа с заявками, зачисление, PII) */
	case FSOffice = 'lms_office';

	// === Роли внешних пользователей (авторизация через провайдеров, без подписки) ===

	/** Свободный ученик (базовый доступ к открытым материалам) */
	case Student = 'lms_student_free';

	/** Свободный преподаватель (базовый доступ без прав на PII) */
	case Teacher = 'lms_teacher_free';

	/**
	 * Возвращает LMS-роли, для которых сброс пароля через wp-login.php запрещён.
	 *
	 * @return list<self>
	 */
	public static function lmsRoles(): array {
		return array( self::FSStudent, self::FSParent, self::FSTeacher );
	}

	/**
	 * Возвращает понятное название роли для отображения в админ-панели.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::FSTeacher => '🎓 LMS: Преподаватель',
			self::FSStudent  => '🎓 LMS: Ученик',
			self::FSParent  => '🎓 LMS: Родитель',
			self::FSOffice  => '🎓 LMS: Администратор',
			self::Student   => '🌐 LMS: Пользователь',
			self::Teacher   => '🌐 LMS: Учитель',
		};
	}

	/**
	 * Возвращает встроенные WordPress-возможности, передаваемые в add_role().
	 *
	 * @return array<string, bool>
	 */
	public function baseCapabilities(): array {
		return match ( $this ) {
			self::FSTeacher => array( 'read' => true, 'edit_posts' => true, 'upload_files' => true ),
			self::FSStudent => array( 'read' => true, 'upload_files' => true ),
			self::FSParent  => array( 'read' => true ),
			self::FSOffice  => array( 'read' => true ),
			self::Student   => array( 'read' => true, 'upload_files' => true ),
			self::Teacher   => array( 'read' => true, 'upload_files' => true ),
		};
	}

	/**
	 * Возвращает кастомные LMS-capabilities роли.
	 * Используется в RoleManager::syncCapabilities() для применения матрицы прав.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities(): array {
		return match ( $this ) {
			self::FSOffice => array(
				Capability::ManageApplications->value => true,
				Capability::EnrollStudent->value      => true,
				Capability::ViewPII->value            => true,
				Capability::ManagePersons->value      => true,
			),
			self::FSTeacher => array(
				Capability::ViewLMSStats->value        => true,
				Capability::ManageLMSAssignments->value => true,
			),
			self::FSStudent, self::FSParent,
			self::Student, self::Teacher => array(),
		};
	}
}
