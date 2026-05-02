<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Inc\DTO\UserDTO;
use Inc\Enums\AuthProvider;

class GoogleAuthStrategy extends AbstractHybridAuthStrategy
{

    public function getProvider(): AuthProvider
    {
        return AuthProvider::GOOGLE;
    }

    public function authenticate(): ?UserDTO
    {
        try {
            $this->initHybrid();
            $adapter = $this->hybridauth->authenticate($this->getProvider()->hybridauthKey());
            $profile = $adapter->getUserProfile();
            $adapter->disconnect();

            // Передаем профиль в сервис для обработки логики WP
            return $this->auth_service->processUserFromSocialProfile($this->getProvider(), $profile);
        } catch (\Exception $e) {
            error_log('LMS Google Auth Error: ' . $e->getMessage());
            return null;
        }
    }
}