<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Inc\DTO\Person\UserDTO;
use Inc\Enums\AuthProvider;
use Inc\Shared\PluginLogger;

class GoogleAuthStrategy extends AbstractHybridAuthStrategy {


	public function getProvider(): AuthProvider {
		return AuthProvider::Google;
	}

	public function authenticate(): ?UserDTO {
		try {
			$this->initHybrid();
			$adapter = $this->hybridauth->authenticate( $this->getProvider()->hybridauthKey() );
			$profile = $adapter->getUserProfile();
			$adapter->disconnect();

			// Передаем профиль в сервис для обработки логики WP
			return $this->auth_service->processUserFromSocialProfile( $this->getProvider(), $profile );
		} catch ( \Exception $e ) {
			PluginLogger::exception( 'GoogleAuthStrategy', $e );
			return null;
		}
	}
}
