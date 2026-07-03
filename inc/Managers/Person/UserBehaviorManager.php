<?php

declare( strict_types=1 );

namespace Inc\Managers\Person;

use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\MetaKeys;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Access\UserRole;

/**
 * Class UserBehaviorManager
 *
 * Управляет поведением пользователей: доступ в админку, фильтрация медиафайлов,
 * редиректы при входе, блокировка сброса пароля и очистка зашифрованных паролей.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **Ограничение доступа в админку** — редирект пользователей без прав с wp-admin.
 * 2. **Фильтрация медиафайлов** — ограничение доступа к чужим вложениям.
 * 3. **Редирект после входа** — перенаправление пользователей на нужные страницы.
 * 4. **Блокировка сброса пароля** — запрет сброса пароля для LMS-ролей.
 * 5. **Очистка зашифрованного пароля** — удаление мета-поля при смене пароля.
 *
 * ### Архитектурная роль:
 *
 * Делегирует поиск пользователей UserManager.
 * Используется в AuthController и других местах для централизованного
 * управления поведением пользователей в системе.
 */
class UserBehaviorManager {

	/**
	 * Конструктор менеджера.
	 *
	 * @param UserManager $userManager Менеджер пользователей
	 */
	public function __construct(
		private readonly UserManager $userManager,
	) {}

	/**
	 * Ограничивает доступ к админ-панели для пользователей без прав.
	 * Перенаправляет на страницу профиля.
	 *
	 * @return void
	 */
	public function restrictAdminAccess(): void {
		// Только фронт wp-admin (не AJAX и не залогиненный интерим-логин).
		if ( ! is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		// Администратор (в т.ч. дуал-роль admin+LMS) проходит всегда.
		if ( current_user_can( Capability::Admin->value ) ) {
			return;
		}

		// Денилист вместо вайтлиста: в админку не пускаем только фронт-кабинетные роли
		// (преподаватель/ученик/родитель/своб. ученик). Офисные роли (FSOffice/методист/
		// маркетолог) — работают в wp-admin, поэтому их НЕ блокируем.
		$frontRoles = array_map( static fn( UserRole $r ): string => $r->value, UserRole::frontCabinetRoles() );
		if ( array_intersect( $frontRoles, (array) wp_get_current_user()->roles ) ) {
			wp_safe_redirect( home_url( '/profile/' ) );
			exit;
		}
	}

	/**
	 * Фильтрует медиафайлы: администратор видит все, другие пользователи — только свои.
	 * Используется в хуках 'ajax_query_attachments_args' и 'request'.
	 *
	 * @param array $query Параметры запроса
	 *
	 * @return array
	 */
	public function getMediaFilterArgs( array $query ): array {
		// Администратор видит все файлы
		if ( current_user_can( Capability::Admin->value ) ) {
			return $query;
		}

		$user_id = get_current_user_id();
		if ( 0 !== $user_id ) {
			// Ограничиваем запрос только файлами текущего пользователя
			$query['author'] = $user_id;
		}

		return $query;
	}

	/**
	 * Определяет URL редиректа после успешного входа в систему.
	 * Подключается к фильтру 'login_redirect'.
	 *
	 * @param string            $redirect_to           Изначальный URL редиректа
	 * @param string            $requested_redirect_to Запрошенный URL редиректа
	 * @param \WP_User|\WP_Error $user                  Объект пользователя или ошибка
	 *
	 * @return string
	 */
	public function resolveLoginRedirect( string $redirect_to, string $requested_redirect_to, \WP_User|\WP_Error $user ): string {
		if ( ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		// Администраторы и редакторы — в админку
		if ( array_intersect( array( 'administrator', 'editor' ), $user->roles ) ) {
			return admin_url();
		}

		// Остальные — на страницу личного кабинета
		return PageRoutes::UserProfile->url();
	}

	/**
	 * Блокирует сброс пароля для пользователей с LMS-ролями.
	 * Подключается к фильтру 'allow_password_reset'.
	 *
	 * @param bool $allow   Разрешён ли сброс пароля
	 * @param int  $userId  ID пользователя
	 *
	 * @return bool
	 */
	public function blockPasswordReset( bool $allow, int $userId ): bool {
		$user = get_userdata( $userId );
		if ( ! $user ) {
			return $allow;
		}

		// userRole::lmsRoles() — статический метод enum, возвращающий список LMS-ролей
		foreach ( UserRole::lmsRoles() as $role ) {
			if ( in_array( $role->value, (array) $user->roles, true ) ) {
				// Запрещаем сброс пароля для LMS-ролей
				return false;
			}
		}
		return $allow;
	}

	/**
	 * Удаляет мета-поле с зашифрованным паролем при смене пароля пользователя.
	 * Вызывается через хук 'profile_update'.
	 *
	 * @param int      $userId  ID пользователя
	 * @param \WP_User $oldUser Старый объект пользователя
	 *
	 * @return void
	 */
	public function clearEncryptedPasswordIfChanged( int $userId, \WP_User $oldUser ): void {
		$newUser = $this->userManager->find( $userId );
		if ( null !== $newUser && $newUser->user_pass !== $oldUser->user_pass ) {
			// delete_user_meta() — удаление мета-поля пользователя
			delete_user_meta( $userId, MetaKeys::EncPassword->value );
		}
	}
}