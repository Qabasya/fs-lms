<?php

declare( strict_types=1 );
namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\UserDTO;
use Inc\Enums\UserRole;

/**
 * Class UserRepository
 *
 * Репозиторий для работы с пользователями системы.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — чтение, обновление и удаление пользователей.
 * 2. **Поиск по роли** — получение списка пользователей определённой роли.
 * 3. **Поиск по социальному ID** — для авторизации через соцсети (Hybridauth).
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций (get_users, wp_update_user, wp_delete_user, get_userdata).
 * Реализует интерфейс RepositoryInterface для единообразия с другими репозиториями.
 */
class UserRepository implements RepositoryInterface {

	/**
	 * Возвращает всех пользователей текущего сайта в виде массива DTO.
	 *
	 * @inheritDoc
	 * @return UserDTO[]
	 */
	public function readAll(): array {
		// get_users() — возвращает массив объектов WP_User
		// get_current_blog_id() — ID текущего сайта (для мультисайтов)
		$users = get_users( array( 'blog_id' => get_current_blog_id() ) );

		if ( empty( $users ) ) {
			return array();
		}

		// array_map() — преобразуем каждый WP_User в UserDTO
		return array_map(
			fn( \WP_User $user ) => UserDTO::fromWPUser( $user ),
			$users
		);
	}

	/**
	 * Обновляет данные пользователя.
	 *
	 * @inheritDoc
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

		// is_wp_error() — проверка на ошибку WordPress
		return ! is_wp_error( $result );
	}

	/**
	 * Удаляет пользователя по ID.
	 *
	 * @inheritDoc
	 * @param array $data Массив с ключами 'ID' и опционально 'reassign'
	 *
	 * @return bool
	 */
	public function delete( array $data ): bool {
		if ( ! isset( $data['ID'] ) ) {
			return false;
		}

		// wp_delete_user() — удаляет пользователя из БД
		// Второй параметр — ID пользователя, которому передаются посты удаляемого
		return wp_delete_user( $data['ID'], $data['reassign'] ?? null );
	}

	// ============================ КАСТОМНЫЕ МЕТОДЫ ============================ //

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
	 * Получает пользователей по роли из Enum и возвращает массив DTO.
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
	 * Поиск пользователя по социальному ID (для Hybridauth).
	 *
	 * @param string $provider   Название соцсети (vk, google, facebook)
	 * @param string $identifier Уникальный ID пользователя в соцсети
	 *
	 * @return UserDTO|null
	 */
	public function getBySocialId( string $provider, string $identifier ): ?UserDTO {
		$users = get_users(
			array(
				// 'meta_key' — имя мета-поля для хранения ID в соцсети
				'meta_key'   => "fs_social_{$provider}_id",
				// 'meta_value' — значение для поиска
				'meta_value' => $identifier,
				// 'number' => 1 — ограничиваем результат одним пользователем
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		if ( empty( $users ) ) {
			return null;
		}

		return UserDTO::fromWPUser( $users[0] );
	}
}
