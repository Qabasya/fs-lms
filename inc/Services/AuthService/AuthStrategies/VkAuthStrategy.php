<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Inc\DTO\UserDTO;
use Inc\Enums\AuthProvider;

class VkAuthStrategy extends AbstractHybridAuthStrategy
{

    public function getProvider(): AuthProvider
    {
        return AuthProvider::VKONTAKTE;
    }

    public function authenticate(): ?UserDTO
    {
        try {
            $this->initHybrid();
            $adapter = $this->hybridauth->authenticate($this->getProvider()->hybridauthKey());
            $profile = $adapter->getUserProfile();
            $adapter->disconnect();

            return $this->auth_service->processUserFromSocialProfile($this->getProvider(), $profile);
        } catch (\Exception $e) {
            error_log('LMS VK Auth Error: ' . $e->getMessage());
            return null;
        }
    }
}