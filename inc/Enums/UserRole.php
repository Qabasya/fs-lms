<?php

namespace Inc\Enums;

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
	 * Возвращает перечень кастомных возможностей (capabilities) для роли.
	 *
	 * @return array<string, bool> Массив [capability => значение]
	 */
	public function capabilities(): array {
		return match ( $this ) {
			// Администратор LMS имеет права на управление заявками, зачисление, PII
			self::FSOffice => array(
				Capability::ManageApplications->value => true,
				Capability::EnrollStudent->value      => true,
				Capability::ViewPII->value            => true,
				Capability::ManagePersons->value      => true,
			),
			// Преподаватель — без прав на PII и зачисление (на данном этапе)
			self::FSTeacher => array(),
			// Родитель — без дополнительных прав
			self::FSParent  => array(),
			// Ученик — без дополнительных прав
			self::FSStudent => array(),
			// Свободные роли без прав
			self::Student, self::Teacher => array(),
		};
	}
}
