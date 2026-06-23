<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Services\AuthStrategies;

use Inc\DTO\Person\UserDTO;
use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Shared\PluginLogger;

class VkAuthStrategy extends AbstractHybridAuthStrategy {

	public function getProvider(): AuthProvider {
		return AuthProvider::Vkontakte;
	}

	public function authenticate(): ?UserDTO {
		try {
			$this->initHybrid();
			$adapter = $this->hybridauth->authenticate( $this->getProvider()->hybridauthKey() );
			$profile = $adapter->getUserProfile();
			$adapter->disconnect();

			return $this->auth_service->processUserFromSocialProfile( $this->getProvider(), $profile );
		} catch ( \Exception $e ) {
			PluginLogger::exception( 'VkAuthStrategy', $e );
			return null;
		}
	}
}
