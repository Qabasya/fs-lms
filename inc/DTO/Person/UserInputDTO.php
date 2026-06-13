<?php

declare( strict_types=1 );

namespace Inc\DTO\Person;

readonly class UserInputDTO {

	public function __construct(
		public string $userLogin,
		public string $userEmail,
		public string $userPass,
		public string $displayName,
		public string $firstName,
		public string $lastName,
		public string $role,
	) {}

	public function toArray(): array {
		return array(
			'user_login'   => $this->userLogin,
			'user_email'   => $this->userEmail,
			'user_pass'    => $this->userPass,
			'display_name' => $this->displayName,
			'first_name'   => $this->firstName,
			'last_name'    => $this->lastName,
			'role'         => $this->role,
		);
	}
}
