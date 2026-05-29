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
	 * Находит WP-пользователя по ID.
	 *
	 * @param int $id ID пользователя WordPress
	 *
	 * @return \WP_User|null null если пользователь не найден
	 */
	public function find( int $id ): ?\WP_User {
		$user = get_userdata( $id );

		return false !== $user ? $user : null;
	}

	/**
	 * Возвращает ID записи person, привязанной к WP-пользователю.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return int|null null если usermeta не установлена
	 */
	public function getPersonId( int $userId ): ?int {
		$value = get_user_meta( $userId, 'fs_lms_person_id', true );

		return $value !== '' && $value !== false ? (int) $value : null;
	}

	/**
	 * Генерирует WP password reset key для пользователя.
	 *
	 * Сохраняет хэш ключа в user_activation_key. Предыдущий ключ
	 * при этом автоматически инвалидируется WordPress.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return string Сырой ключ для включения в URL (не хэшированный)
	 *
	 * @throws \RuntimeException Если пользователь не найден или WP вернул WP_Error
	 */
	public function generatePasswordResetKey( int $userId ): string {
		$user = $this->find( $userId );

		if ( null === $user ) {
			throw new \RuntimeException( "Пользователь с ID {$userId} не найден." );
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			throw new \RuntimeException(
				'Ошибка генерации ключа сброса пароля: ' . $key->get_error_message()
			);
		}

		return $key;
	}

	/**
	 * Инвалидирует ссылку сброса пароля, очищая user_activation_key.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return void
	 *
	 * @throws \RuntimeException Если пользователь не найден или обновление не удалось
	 */
	public function clearActivationKey( int $userId ): void {
		if ( null === $this->find( $userId ) ) {
			throw new \RuntimeException( "Пользователь с ID {$userId} не найден." );
		}

		$result = wp_update_user( array(
			'ID'                  => $userId,
			'user_activation_key' => '',
		) );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException(
				'Ошибка инвалидации ключа: ' . $result->get_error_message()
			);
		}
	}

	public function create( array $data ): int {
		$result = wp_insert_user( $data );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'Ошибка создания пользователя: ' . $result->get_error_message() );
		}

		return (int) $result;
	}

	public function update( int $id, array $data ): bool {
		$result = wp_update_user( array_merge( $data, array( 'ID' => $id ) ) );

		return ! is_wp_error( $result );
	}

	public function findByEmail( string $email ): ?\WP_User {
		$user = get_user_by( 'email', $email );

		return false !== $user ? $user : null;
	}

	public function findByLogin( string $login ): ?\WP_User {
		$user = get_user_by( 'login', $login );

		return false !== $user ? $user : null;
	}

	public function exists( int $id ): bool {
		return false !== get_userdata( $id );
	}

	public function setRole( int $id, string $role ): void {
		$user = $this->find( $id );

		if ( null !== $user ) {
			$user->set_role( $role );
		}
	}

	public function addRole( int $id, string $role ): void {
		$user = $this->find( $id );

		if ( null !== $user ) {
			$user->add_role( $role );
		}
	}

	public function removeRole( int $id, string $role ): void {
		$user = $this->find( $id );

		if ( null !== $user ) {
			$user->remove_role( $role );
		}
	}

	public function randomizePassword( int $id ): void {
		wp_set_password( wp_generate_password( 64, true, true ), $id );
	}

	public function setPersonId( int $userId, int $personId ): void {
		update_user_meta( $userId, 'fs_lms_person_id', $personId );
	}

	public function setStatus( int $userId, string $status ): void {
		update_user_meta( $userId, 'fs_lms_user_status', $status );
	}

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