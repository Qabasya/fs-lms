<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Services;

use Inc\Modules\SocialAuth\Contracts\AuthStrategyInterface;
use Inc\Modules\SocialAuth\Enums\AuthProvider;
use Inc\Modules\SocialAuth\Services\AuthStrategies\GithubAuthStrategy;
use Inc\Modules\SocialAuth\Services\AuthStrategies\GoogleAuthStrategy;
use Inc\Modules\SocialAuth\Services\AuthStrategies\VkAuthStrategy;

class AuthStrategyRegistry {

	private array $strategies;

	public function __construct(
		GoogleAuthStrategy $google_strategy,
		VkAuthStrategy $vk_strategy,
		GithubAuthStrategy $github_strategy,
	) {
		$this->strategies = array(
			AuthProvider::Google->value    => $google_strategy,
			AuthProvider::Vkontakte->value => $vk_strategy,
			AuthProvider::Github->value    => $github_strategy,
		);
	}

	public function get( ?AuthProvider $provider ): ?AuthStrategyInterface {
		if ( ! $provider ) {
			return null;
		}

		return $this->strategies[ $provider->value ] ?? null;
	}
}
