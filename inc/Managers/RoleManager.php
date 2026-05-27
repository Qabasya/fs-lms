<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\Enums\Capability;
use Inc\Enums\UserRole;

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
 * | Capability          | admin | lms_office | lms_teacher |
 * |---------------------|-------|------------|-------------|
 * | ManageApplications  |   ✓   |     ✓      |             |
 * | EnrollStudent       |   ✓   |     ✓      |             |
 * | ViewPII             |   ✓   |     ✓      |             |
 * | ExportPII           |   ✓   |            |             |
 * | ManagePersons       |   ✓   |     ✓      |             |
 * | ViewLMSStats        |       |            |      ✓      |
 * | ManageLMSAssignments|       |            |      ✓      |
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
			$admin->add_cap( Capability::ManageApplications->value );
			$admin->add_cap( Capability::EnrollStudent->value );
			$admin->add_cap( Capability::ViewPII->value );
			$admin->add_cap( Capability::ExportPII->value );
			$admin->add_cap( Capability::ManagePersons->value );
		}
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
}