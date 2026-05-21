<?php

declare( strict_types=1 );

namespace Inc\Repositories;

use Inc\DTO\UserDTO;
use Inc\Enums\UserRole;

class UserRepository {

	/** @return UserDTO[] */
	public function readAll(): array {
		$users = get_users( array( 'blog_id' => get_current_blog_id() ) );

		return array_map(
			fn( \WP_User $user ) => UserDTO::fromWPUser( $user ),
			$users
		);
	}

	public function getById( int $user_id ): ?UserDTO {
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User ? UserDTO::fromWPUser( $user ) : null;
	}

	public function getByEmail( string $email ): ?UserDTO {
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User ? UserDTO::fromWPUser( $user ) : null;
	}

	/** @return UserDTO[] */
	public function getByRole( UserRole $role ): array {
		$users = get_users( array( 'role' => $role->value ) );
		return array_map(
			fn( \WP_User $user ) => UserDTO::fromWPUser( $user ),
			$users
		);
	}

	public function getBySocialId( string $provider, string $identifier ): ?UserDTO {
		$users = get_users( array(
			'meta_key'   => "fs_social_{$provider}_id",
			'meta_value' => $identifier,
			'number'     => 1,
			'fields'     => 'all',
		) );

		return empty( $users ) ? null : UserDTO::fromWPUser( $users[0] );
	}

	public function create( array $data ): ?UserDTO {
		$user_id = wp_insert_user( array(
			'user_login'   => $data['user_login'],
			'user_email'   => $data['user_email'],
			'display_name' => $data['display_name'],
			'role'         => $data['role'],
			'user_pass'    => wp_generate_password(),
		) );

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		if ( ! empty( $data['meta'] ) ) {
			$this->updateMeta( $user_id, $data['meta'] );
		}

		return $this->getById( $user_id );
	}

	public function update( array $data ): bool {
		if ( ! isset( $data['ID'] ) ) {
			return false;
		}
		$result = wp_update_user( $data );
		return ! is_wp_error( $result );
	}

	public function delete( int $user_id, ?int $reassign = null ): bool {
		return wp_delete_user( $user_id, $reassign );
	}

	public function updateMeta( int $user_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}
	}
}
