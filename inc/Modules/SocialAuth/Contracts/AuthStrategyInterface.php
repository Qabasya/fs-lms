<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Contracts;

use Inc\DTO\Person\UserDTO;
use Inc\Modules\SocialAuth\Enums\AuthProvider;

interface AuthStrategyInterface {
	public function getProvider(): AuthProvider;
	public function login(): void;
	public function authenticate(): ?UserDTO;
}
