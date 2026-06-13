<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\DTO\Person\UserInputDTO;
use Inc\Enums\MetaKeys;

/**
 * Class UserManager
 *
 * CRUD-обёртка над WordPress User API.
 *
 * @package Inc\Managers
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
		$value = get_user_meta( $userId, MetaKeys::PersonID->value, true );

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

	public function create( UserInputDTO $dto ): int {
		$result = wp_insert_user( $dto->toArray() );

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

	public function delete( int $id ): void {
		wp_delete_user( $id );
	}

	public function randomizePassword( int $id ): void {
		wp_set_password( wp_generate_password( 64, true, true ), $id );
	}

	public function setPersonId( int $userId, int $personId ): void {
		update_user_meta( $userId, MetaKeys::PersonID->value, $personId );
	}

	public function setStatus( int $userId, string $status ): void {
		update_user_meta( $userId, MetaKeys::UserStatus->value, $status );
	}

}