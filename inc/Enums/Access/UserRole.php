<?php

declare( strict_types=1 );

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
 */
enum UserRole: string {

	// === Защищённые роли (авторизация логин-пароль) ===

	/** Преподаватель с полным доступом к учебным материалам */
	case FSTeacher = 'lms_teacher';

	/** Ученик с доступом к учебным материалам */
	case FSStudent = 'lms_student';

	/** Родитель ученика (просмотр прогресса и управление детьми) */
	case FSParent = 'lms_parent';

	/** Администратор LMS (работа с заявками, зачисление, PII, все разделы плагина) */
	case FSOffice = 'lms_office';

	/** Методист: авторинг курсов, уроков, работ, контрольных, задач */
	case FSMethodist = 'lms_methodist';

	/** Маркетолог: статьи, статистика */
	case FSMarket = 'lms_market';

	/**
	 * Возвращает «основную» роль из набора слагов по приоритету.
	 * Приоритет убывает: FSOffice → FSMethodist → FSMarket → FSTeacher → FSStudent → FSParent.
	 * Если ни один слаг не совпал — возвращает FSStudent (нет отдельной «безроли»).
	 *
	 * @param string[] $slugs Список слагов из $user->roles
	 */
	public static function primary( array $slugs ): self {
		foreach ( array( self::FSOffice, self::FSMethodist, self::FSMarket, self::FSTeacher,
						 self::FSStudent, self::FSParent ) as $role ) {
			if ( in_array( $role->value, $slugs, true ) ) {
				return $role;
			}
		}
		return self::FSStudent;
	}

	/**
	 * Основная роль для личного кабинета (T12.1, D-суперсет): чистый WP-администратор
	 * (нет ни одной LMS-роли в $slugs) получает {@see self::FSOffice} — суперсет доступа,
	 * офисная витрина со всеми группами (см. `ProfileViewResolver::jsConfig`). Дуал-роль
	 * admin+LMS (напр. admin+FSTeacher) резолвится обычным приоритетом {@see self::primary()}
	 * без изменений — эта ветка только для случая, когда LMS-ролей нет вообще.
	 *
	 * @param string[] $slugs
	 */
	public static function primaryForCabinet( array $slugs ): self {
		$hasLmsRole = array_filter( $slugs, static fn( string $s ): bool => null !== self::tryFrom( $s ) );
		if ( empty( $hasLmsRole ) && in_array( 'administrator', $slugs, true ) ) {
			return self::FSOffice;
		}
		return self::primary( $slugs );
	}

	/**
	 * То же, что primary(), но возвращает строку-слаг.
	 * Для пользователей без LMS-роли (например, administrator) — возвращает первый сырой слаг.
	 *
	 * @param string[] $slugs
	 */
	public static function primarySlug( array $slugs ): string {
		$hasKnown = array_filter( $slugs, static fn( $s ) => self::tryFrom( (string) $s ) !== null );
		if ( ! empty( $hasKnown ) ) {
			return self::primary( $slugs )->value;
		}
		return (string) ( reset( $slugs ) ?: '' );
	}

	/**
	 * Возвращает LMS-роли, для которых сброс пароля через wp-login.php запрещён.
	 *
	 * @return list<self>
	 */
	public static function lmsRoles(): array {
		return array( self::FSStudent, self::FSParent, self::FSTeacher );
	}

	/**
	 * Роли, чей дом — фронт-кабинет `/profile/` (или публичная часть), а не wp-admin:
	 * преподаватель, ученик, родитель. Именно их
	 * {@see \Inc\Managers\Person\UserBehaviorManager::restrictAdminAccess()} не пускает
	 * в админку. У всех есть витрина ({@see \Inc\Services\Profile\ProfileViewResolver::viewFor()}),
	 * поэтому редирект на `/profile/` не создаёт петлю. Офисные роли
	 * (FSOffice/FSMethodist/FSMarket) в этот список НЕ входят — им админка доступна.
	 *
	 * @return list<self>
	 */
	public static function frontCabinetRoles(): array {
		return array( self::FSTeacher, self::FSStudent, self::FSParent );
	}

	/**
	 * Возвращает понятное название роли для отображения в админ-панели.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::FSTeacher   => '🎓 LMS: Преподаватель',
			self::FSStudent   => '🎓 LMS: Ученик',
			self::FSParent    => '🎓 LMS: Родитель',
			self::FSOffice    => '🎓 LMS: Администратор платформы',
			self::FSMethodist => '🎓 LMS: Методист',
			self::FSMarket    => '🎓 LMS: Маркетолог',
		};
	}

	/**
	 * Возвращает встроенные WordPress-возможности, передаваемые в add_role().
	 *
	 * @return array<string, bool>
	 */
	public function baseCapabilities(): array {
		return match ( $this ) {
			self::FSTeacher,
			self::FSMethodist,
			self::FSMarket  => array( 'read' => true, 'edit_posts' => true, 'upload_files' => true ),
			self::FSStudent => array( 'read' => true, 'upload_files' => true ),
			self::FSParent  => array( 'read' => true ),
			self::FSOffice  => array( 'read' => true ),
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
				Capability::ManageLmsPlatform->value    => true,
				Capability::ViewLMSStats->value         => true,
				Capability::ExportPII->value            => true,
				Capability::AuthorLmsCourses->value     => true,
				Capability::ManageLmsArticles->value    => true,
				Capability::ManageLmsTeaching->value    => true,
				Capability::ManageApplications->value   => true,
				Capability::EnrollStudent->value        => true,
				Capability::ViewPII->value              => true,
				Capability::ManagePersons->value        => true,
				Capability::ManageSchedule->value       => true,
			),
			self::FSMethodist => array(
				Capability::AuthorLmsCourses->value => true,
			),
			self::FSMarket => array(
				Capability::ManageLmsArticles->value => true,
				Capability::ViewLMSStats->value      => true,
			),
			self::FSTeacher => array(
				Capability::ViewLMSStats->value      => true,
				Capability::ManageLmsTeaching->value => true,
			),
			self::FSStudent, self::FSParent => array(),
		};
	}
}
