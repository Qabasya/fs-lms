<?php

declare( strict_types=1 );

namespace Inc\Managers\Person;

use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;

/**
 * Class RoleManager
 *
 * Менеджер регистрации LMS-ролей и управления их capabilities.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация ролей** — создание всех LMS-ролей при активации плагина.
 * 2. **Синхронизация capabilities** — приведение матрицы прав к актуальному состоянию.
 * 3. **Удаление ролей** — очистка ролей при деактивации плагина.
 *
 * ### Матрица capabilities:
 *
 * | Capability            | admin | lms_office | lms_methodist | lms_teacher |
 * |-----------------------|-------|------------|---------------|-------------|
 * | ManageLmsPlatform     |   ✓   |     ✓      |               |             |
 * | ManageLmsRoles        |   ✓   |            |               |             |
 * | AuthorLmsCourses      |   ✓   |     ✓      |       ✓       |             |
 * | ManageLmsArticles     |   ✓   |     ✓      |               |             |
 * | ManageLmsTeaching     |   ✓   |     ✓      |               |      ✓      |
 * | ManageSchedule        |   ✓   |     ✓      |               |             |
 * | ManageApplications    |   ✓   |     ✓      |               |             |
 * | EnrollStudent         |   ✓   |     ✓      |               |             |
 * | ViewPII               |   ✓   |     ✓      |               |             |
 * | ExportPII             |   ✓   |     ✓      |               |             |
 * | ManagePersons         |   ✓   |     ✓      |               |             |
 * | ViewLMSStats          |   ✓   |     ✓      |               |      ✓      |
 * | fs_lms_content caps   |   ✓   |     ✓      |       ✓       |      ✓      |
 * | fs_lms_article caps   |   ✓   |     ✓      |               |             |
 *
 * ### Lifecycle:
 *
 * - `registerAll()` → вызывается из Activate::activate()
 * - `unregisterAll()` → вызывается из Deactivate::deactivate()
 * - `syncCapabilities()` → вызывается при обновлении плагина
 */
class RoleManager {

	/**
	 * Регистрирует все LMS-роли и синхронизирует capabilities.
	 * Вызывается при активации плагина. Идемпотентен: add_role() игнорирует уже существующие роли.
	 *
	 * @return void
	 */
	public function registerAll(): void {
		foreach ( UserRole::cases() as $role ) {
			add_role( $role->value, $role->label(), $role->baseCapabilities() );
		}

		// Свободные роли Student/Teacher сняты с плана (Этап 4 подготовки к релизу),
		// роль «Маркетолог» снята как нефункциональная (её единственные права —
		// ManageLmsArticles, недостижимый в UI из-за capability родительского меню
		// «Обучение», и ViewLMSStats, не гейтивший ничего — статьями занимается
		// администратор платформы, у которого ManageLmsArticles уже есть):
		// на dev-установках, где они успели создаться, add_role() их больше не
		// восстановит — снимаем явно, remove_role() безопасно вызывать на
		// несуществующей роли.
		remove_role( 'lms_student_free' );
		remove_role( 'lms_teacher_free' );
		remove_role( 'lms_market' );

		$this->syncCapabilities();
	}

	/**
	 * Приводит capabilities всех ролей к актуальной матрице.
	 * Вызывается при обновлении плагина, когда матрица изменилась.
	 * Логика идемпотентна: add_cap() безопасно вызывать повторно.
	 *
	 * @return void
	 */
	public function syncCapabilities(): void {
		foreach ( UserRole::cases() as $role ) {
			$wp_role = get_role( $role->value );
			if ( null === $wp_role ) {
				continue;
			}
			foreach ( $role->capabilities() as $cap => $grant ) {
				$wp_role->add_cap( $cap, $grant );
			}
		}

		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			$admin->add_cap( Capability::ManageLmsPlatform->value );
			$admin->add_cap( Capability::ManageLmsRoles->value );
			$admin->add_cap( Capability::AuthorLmsCourses->value );
			$admin->add_cap( Capability::ManageLmsArticles->value );
			$admin->add_cap( Capability::ManageLmsTeaching->value );
			$admin->add_cap( Capability::ManageSchedule->value );
			$admin->add_cap( Capability::ManageApplications->value );
			$admin->add_cap( Capability::EnrollStudent->value );
			$admin->add_cap( Capability::ViewPII->value );
			$admin->add_cap( Capability::ExportPII->value );
			$admin->add_cap( Capability::ManagePersons->value );
			$admin->add_cap( Capability::ViewLMSStats->value );
			foreach ( self::lessonCaps() as $cap ) {
				$admin->add_cap( $cap );
			}
			foreach ( self::articleCaps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		foreach ( array( 'lms_methodist', 'lms_office' ) as $slug ) {
			$role = get_role( $slug );
			if ( null !== $role ) {
				foreach ( self::lessonCaps() as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}

		// Этап 5+8: снять caps авторинга с teacher; убрать retire-cap со всех ролей.
		$teacher = get_role( 'lms_teacher' );
		if ( null !== $teacher ) {
			foreach ( self::lessonCaps() as $cap ) {
				$teacher->remove_cap( $cap );
			}
		}

		// Откат авторинга банка преподавателем: курс (включая банк задач/работ/
		// контрольных) составляет только методист в админке — cap снимается со всех
		// ролей на живых установках, где он успел выдаться.
		foreach ( array( 'administrator', 'lms_methodist', 'lms_office', 'lms_teacher' ) as $slug ) {
			$role = get_role( $slug );
			if ( null !== $role ) {
				$role->remove_cap( 'author_lms_bank' );
			}
		}
		foreach ( array( 'administrator', 'lms_office', 'lms_methodist', 'lms_teacher' ) as $slug ) {
			$r = get_role( $slug );
			if ( null !== $r ) {
				$r->remove_cap( 'manage_lms_assignments' );
			}
		}

		$office = get_role( 'lms_office' );
		if ( null !== $office ) {
			foreach ( self::articleCaps() as $cap ) {
				$office->add_cap( $cap );
			}
		}
	}

	/**
	 * Производные capabilities CPT статей (capability_type = fs_lms_article).
	 * Выдаются administrator, lms_office — но НЕ lms_methodist.
	 *
	 * @return string[]
	 */
	private static function articleCaps(): array {
		return array(
			'edit_fs_lms_article',
			'read_fs_lms_article',
			'delete_fs_lms_article',
			'edit_fs_lms_articles',
			'edit_others_fs_lms_articles',
			'publish_fs_lms_articles',
			'read_private_fs_lms_articles',
			'delete_fs_lms_articles',
			'delete_others_fs_lms_articles',
			'delete_published_fs_lms_articles',
			'delete_private_fs_lms_articles',
			'edit_private_fs_lms_articles',
			'edit_published_fs_lms_articles',
			'create_fs_lms_articles',
		);
	}

	/**
	 * Производные capabilities CPT уроков (capability_type = fs_lms_content).
	 *
	 * @return string[]
	 */
	private static function lessonCaps(): array {
		return array(
			'edit_fs_lms_content',
			'read_fs_lms_content',
			'delete_fs_lms_content',
			'edit_fs_lms_contents',
			'edit_others_fs_lms_contents',
			'publish_fs_lms_contents',
			'read_private_fs_lms_contents',
			'delete_fs_lms_contents',
			'delete_others_fs_lms_contents',
			'delete_published_fs_lms_contents',
			'delete_private_fs_lms_contents',
			'edit_private_fs_lms_contents',
			'edit_published_fs_lms_contents',
			'create_fs_lms_contents',
		);
	}

	/**
	 * Удаляет все LMS-роли из WordPress.
	 * Вызывается при деактивации плагина.
	 *
	 * @return void
	 */
	public function unregisterAll(): void {
		foreach ( UserRole::cases() as $role ) {
			remove_role( $role->value );
		}
	}

	/**
	 * Снимает с роли `administrator` все права, выданные плагином.
	 *
	 * Вызывается из `uninstall.php`. Деактивация сносит только LMS-роли, а права,
	 * дописанные администратору в {@see syncCapabilities()}, лежат в опции
	 * `wp_user_roles` и без этого остаются в базе после удаления плагина навсегда.
	 *
	 * Список берётся из тех же источников, что и выдача ({@see pluginGrantedCaps}) —
	 * захардкоженная копия однажды уже разошлась с энумом и роняла удаление плагина
	 * фатальной ошибкой на несуществующем кейсе.
	 *
	 * @return void
	 */
	public function purgeAdminCaps(): void {
		$admin = get_role( 'administrator' );

		if ( null === $admin ) {
			return;
		}

		foreach ( self::pluginGrantedCaps() as $cap ) {
			$admin->remove_cap( $cap );
		}
	}

	/**
	 * Все capabilities, которые плагин выдаёт администратору: LMS-права из
	 * {@see Capability} плюс производные права CPT уроков и статей.
	 *
	 * `manage_options` и `manage_categories` намеренно исключены: это штатные
	 * права WordPress, плагин их не создавал и снимать при удалении не вправе —
	 * иначе администратор потеряет доступ к настройкам всего сайта.
	 *
	 * @return string[]
	 */
	private static function pluginGrantedCaps(): array {
		$caps = array();

		foreach ( Capability::cases() as $cap ) {
			if ( Capability::Admin === $cap || Capability::ManageTerms === $cap ) {
				continue;
			}

			$caps[] = $cap->value;
		}

		return array_merge( $caps, self::lessonCaps(), self::articleCaps() );
	}
}