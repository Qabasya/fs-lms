<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\DTO\Person\UserDTO;
use Inc\Enums\UserRole;

/**
 * Class UserRepository
 *
 * Репозиторий для работы с пользователями WordPress.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление пользователей.
 * 2. **Поиск по различным критериям** — по ID, email, роли, социальному ID.
 * 3. **Работа с мета-полями** — массовое обновление пользовательских мета-полей.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций (get_users, get_userdata, get_user_by,
 * wp_insert_user, wp_update_user, wp_delete_user, update_user_meta).
 * Использует DTO UserDTO для типобезопасной передачи данных между слоями приложения.
 */
class UserRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Возвращает всех пользователей текущего сайта в виде массива DTO.
	 *
	 * @return UserDTO[]
	 */
	public function readAll(): array {
		// get_users() — возвращает массив объектов WP_User
		// get_current_blog_id() — ID текущего сайта (для мультисайтов)
		$users = get_users( array( 'blog_id' => get_current_blog_id() ) );

		return array_map(
			fn( \WP_User $user ) => UserDTO::fromWPUser( $user ),
			$users
		);
	}

	/**
	 * Получает пользователя по ID.
	 *
	 * @param int $user_id ID пользователя
	 *
	 * @return UserDTO|null
	 */
	public function getById( int $user_id ): ?UserDTO {
		// get_userdata() — возвращает объект WP_User или false
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User ? UserDTO::fromWPUser( $user ) : null;
	}

	/**
	 * Получает пользователя по email.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return UserDTO|null
	 */
	public function getByEmail( string $email ): ?UserDTO {
		// get_user_by() — получает пользователя по указанному полю (email, login, slug)
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? UserDTO::fromWPUser( $user ) : null;
	}

	/**
	 * Возвращает пользователей с указанной ролью.
	 *
	 * @param UserRole $role Роль пользователя
	 *
	 * @return UserDTO[]
	 */
	public function getByRole( UserRole $role ): array {
		$users = get_users( array( 'role' => $role->value ) );
		return array_map(
			fn( \WP_User $user ) => UserDTO::fromWPUser( $user ),
			$users
		);
	}

	/**
	 * Поиск пользователя по социальному ID (для авторизации через соцсети).
	 *
	 * @param string $provider   Название соцсети (google, vk, github)
	 * @param string $identifier Уникальный ID пользователя в соцсети
	 *
	 * @return UserDTO|null
	 */
	public function getBySocialId( string $provider, string $identifier ): ?UserDTO {
		$users = get_users( array(
			// 'meta_key' — имя мета-поля для хранения ID в соцсети
			'meta_key'   => "fs_social_{$provider}_id",
			// 'meta_value' — значение для поиска
			'meta_value' => $identifier,
			'number'     => 1,       // Нужен только первый результат
			'fields'     => 'all',   // Возвращаем полные объекты WP_User
		) );

		return empty( $users ) ? null : UserDTO::fromWPUser( $users[0] );
	}

	/**
	 * Создаёт нового пользователя.
	 *
	 * @param array $data Данные пользователя (user_login, user_email, display_name, role, meta)
	 *
	 * @return UserDTO|null
	 */
	public function create( array $data ): ?UserDTO {
		// wp_insert_user() — создаёт пользователя, возвращает ID или WP_Error
		$user_id = wp_insert_user( array(
			'user_login'   => $data['user_login'],
			'user_email'   => $data['user_email'],
			'display_name' => $data['display_name'],
			'role'         => $data['role'],
			// wp_generate_password() — генерирует случайный пароль (пользователь войдёт через соцсеть)
			'user_pass'    => wp_generate_password(),
		) );

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		// Сохранение мета-полей (привязка соцсети, аватар и т.д.)
		if ( ! empty( $data['meta'] ) ) {
			$this->updateMeta( $user_id, $data['meta'] );
		}

		return $this->getById( $user_id );
	}

	/**
	 * Обновляет данные пользователя.
	 *
	 * @param array $data Массив данных пользователя (должен содержать ключ 'ID')
	 *
	 * @return bool
	 */
	public function update( array $data ): bool {
		if ( ! isset( $data['ID'] ) ) {
			return false;
		}
		// wp_update_user() — обновляет пользователя, возвращает ID или WP_Error
		$result = wp_update_user( $data );
		return ! is_wp_error( $result );
	}

	/**
	 * Удаляет пользователя.
	 *
	 * @param int      $user_id  ID пользователя
	 * @param int|null $reassign ID пользователя, которому передаются посты удаляемого
	 *
	 * @return bool
	 */
	public function delete( int $user_id, ?int $reassign = null ): bool {
		// wp_delete_user() — удаляет пользователя из БД
		return wp_delete_user( $user_id, $reassign );
	}

	/**
	 * Массовое обновление мета-полей пользователя.
	 *
	 * @param int   $user_id ID пользователя
	 * @param array $meta    Массив [meta_key => meta_value]
	 *
	 * @return void
	 */
	public function updateMeta( int $user_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}
	}

	/**
	 * Возвращает одно мета-поле пользователя.
	 *
	 * @param int    $user_id ID пользователя
	 * @param string $key     Ключ мета-поля
	 *
	 * @return mixed Значение поля или пустая строка если не найдено
	 */
	public function getMeta( int $user_id, string $key ): mixed {
		return get_user_meta( $user_id, $key, true );
	}

	/**
	 * Удаляет мета-поле пользователя.
	 *
	 * @param int    $user_id ID пользователя
	 * @param string $key     Ключ мета-поля
	 *
	 * @return void
	 */
	public function deleteMeta( int $user_id, string $key ): void {
		delete_user_meta( $user_id, $key );
	}
}