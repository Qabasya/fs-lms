<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\Enums\Capability;
use Inc\Enums\PageRoutes;
use Inc\Enums\UserRole;

/**
 * Class UserManager
 *
 * Менеджер runtime-поведения пользователей.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **Ограничение доступа** — редирект пользователей без прав с админ-панели на фронтенд.
 * 2. **Фильтрация медиафайлов** — ограничение видимости загрузок по автору.
 * 3. **Разрешение редиректа после входа** — определение целевого URL после login.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует runtime-вызовы WordPress API, связанные с текущим пользователем.
 * Управление ролями и capabilities вынесено в RoleManager.
 */
class UserManager {

	/**
	 * Ограничивает доступ к админ-панели для всех, кроме администраторов и LMS-преподавателей.
	 * Подключается к хуку 'admin_init'.
	 *
	 * @return void
	 */
	public function restrictAdminAccess(): void {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			if ( is_user_logged_in() &&
				! current_user_can( Capability::Admin->value ) &&
				! current_user_can( UserRole::FSTeacher->value )
			) {
				wp_safe_redirect( home_url( '/profile/' ) );
				exit;
			}
		}
	}

	/**
	 * Формирует аргументы запроса к медиабиблиотеке так, чтобы пользователи
	 * видели только свои загрузки.
	 *
	 * @param array $query Аргументы запроса WP_Query
	 * @return array
	 */
	public function getMediaFilterArgs( array $query ): array {
		if ( current_user_can( Capability::Admin->value ) ) {
			return $query;
		}

		$user_id = get_current_user_id();
		if ( 0 !== $user_id ) {
			$query['author'] = $user_id;
		}

		return $query;
	}

	/**
	 * Определяет URL редиректа после успешного входа пользователя.
	 * Администраторы и редакторы направляются в админ-панель, остальные — в профиль.
	 *
	 * @param string                $redirect_to           URL по умолчанию
	 * @param string                $requested_redirect_to URL, запрошенный пользователем
	 * @param \WP_User|\WP_Error    $user                  Объект авторизованного пользователя
	 * @return string
	 */
	public function resolveLoginRedirect( string $redirect_to, string $requested_redirect_to, \WP_User|\WP_Error $user ): string {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		if ( array_intersect( array( 'administrator', 'editor' ), $user->roles ) ) {
			return admin_url();
		}

		return PageRoutes::UserProfile->url();
	}
}