<?php

declare( strict_types=1 );

namespace Inc\DTO;

use Inc\Enums\UserRole;

readonly class UserDTO {
	public function __construct(
		public int $id,
		public string $email,
		public string $displayName,
		public UserRole $role,
		public ?string $telegramId = null,
		public array $meta = array()
	) {
	}

	/**
	 * Статический метод для создания DTO из WP_User
	 */
	public static function fromWPUser( \WP_User $user ): self {
		// Получаем роль из нашего Enum (берем первую совпавшую)
		$userRole = UserRole::Student; // дефолт
		foreach ( UserRole::cases() as $role ) {
			if ( in_array( $role->value, $user->roles ) ) {
				$userRole = $role;
				break;
			}
		}

		return new self(
			id         : $user->ID,
			email      : $user->user_email,
			displayName: $user->display_name,
			role       : $userRole,
			telegramId : get_user_meta( $user->ID, 'fs_telegram_id', true ) ?: null,
			meta       : array() // Сюда можно подгрузить остальное
		);
	}
}
