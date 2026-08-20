<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

use Inc\Enums\Access\UserRole;

/**
 * Class UserDTO
 *
 * Data Transfer Object для передачи данных о пользователе.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — обеспечивает строгую типизацию данных пользователя.
 * 2. **Фабричные методы** — создание DTO из WP_User и преобразование в массив.
 *
 * ### Архитектурная роль:
 *
 * Используется для передачи данных между слоями:
 * - Из UserRepository в сервисы и контроллеры
 * - Для обмена данными о пользователе между компонентами плагина
 */
readonly class UserDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id          ID пользователя
	 * @param string      $email       Email пользователя
	 * @param string      $displayName Отображаемое имя пользователя
	 * @param UserRole    $role        Роль пользователя (из enum)
	 * @param array       $meta        Дополнительные мета-данные пользователя
	 */
	public function __construct(
		public int $id,
		public string $email,
		public string $displayName,
		public UserRole $role,
		public array $meta = array()
	) {
	}

	/**
	 * Преобразует DTO в массив для сохранения в БД или передачи.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'email'       => $this->email,
			'displayName' => $this->displayName,
			'role'        => $this->role->value,
			'meta'        => $this->meta,
		);
	}

	/**
	 * Создаёт DTO из объекта WP_User.
	 *
	 * @param \WP_User $user Объект пользователя WordPress
	 *
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id:          (int)    ( $data['id'] ?? 0 ),
			email:       (string) ( $data['email'] ?? '' ),
			displayName: (string) ( $data['displayName'] ?? '' ),
			role:        UserRole::tryFrom( (string) ( $data['role'] ?? '' ) ) ?? UserRole::FSStudent,
			meta:        (array)  ( $data['meta'] ?? array() ),
		);
	}

	public static function fromWPUser( \WP_User $user ): self {
		$userRole = UserRole::primary( (array) $user->roles );

		return new self(
			id         : $user->ID,
			email      : $user->user_email,
			displayName: $user->display_name,
			role       : $userRole,
			meta       : array()  // Сюда можно подгрузить остальные мета-поля
		);
	}
}