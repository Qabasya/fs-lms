<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Services\AuthStrategies;

use Hybridauth\Exception\InvalidArgumentException;
use Hybridauth\Exception\UnexpectedValueException;
use Hybridauth\Hybridauth;
use Inc\Modules\SocialAuth\Contracts\AuthStrategyInterface;
use Inc\Modules\SocialAuth\Services\AuthConfigFactory;
use Inc\Modules\SocialAuth\Services\AuthService;

abstract class AbstractHybridAuthStrategy implements AuthStrategyInterface {

	protected ?Hybridauth $hybridauth = null;

	public function __construct(
		protected AuthConfigFactory $config_factory,
		protected AuthService $auth_service
	) {}

	protected function initHybrid(): void {
		if ( ! $this->hybridauth ) {
			$this->hybridauth = new Hybridauth( $this->config_factory->make( $this->getProvider() ) );
		}
	}

	public function login(): void {
		$this->initHybrid();
		$this->hybridauth->authenticate( $this->getProvider()->hybridauthKey() );
	}
}
