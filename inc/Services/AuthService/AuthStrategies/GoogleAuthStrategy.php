<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Inc\DTO\UserDTO;
use Inc\Enums\AuthProvider;

class GoogleAuthStrategy extends AbstractHybridAuthStrategy {


	public function getProvider(): AuthProvider {
		return AuthProvider::GOOGLE;
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
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[FS LMS] GoogleAuthStrategy: ' . $e->getMessage() . ' | Context: ' . wp_json_encode( array( 'file' => $e->getFile(), 'line' => $e->getLine() ), JSON_UNESCAPED_UNICODE ) );
			}
			return null;
		}
	}
}
