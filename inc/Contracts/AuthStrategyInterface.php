<?php

namespace Inc\Contracts;

use Inc\DTO\UserDTO;
use Inc\Enums\AuthProvider;

interface AuthStrategyInterface
{
    public function getProvider(): AuthProvider;
    public function login(): void;
    public function authenticate(): ?UserDTO;
}