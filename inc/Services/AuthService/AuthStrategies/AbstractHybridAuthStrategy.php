<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Hybridauth\Exception\InvalidArgumentException;
use Hybridauth\Exception\UnexpectedValueException;
use Hybridauth\Hybridauth;
use Inc\Contracts\AuthStrategyInterface;
use Inc\Services\AuthService\AuthConfigFactory;
use Inc\Services\AuthService\AuthService;

abstract class AbstractHybridAuthStrategy implements AuthStrategyInterface
{
    protected ?Hybridauth $hybridauth = null;

    public function __construct(
        protected AuthConfigFactory $config_factory,
        protected AuthService $auth_service
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    protected function initHybrid(): void {
        if (!$this->hybridauth) {
            $this->hybridauth = new Hybridauth($this->config_factory->make($this->getProvider()));
        }
    }

    /**
     * @throws UnexpectedValueException
     * @throws InvalidArgumentException
     */
    public function login(): void {
        $this->initHybrid();
        $this->hybridauth->authenticate($this->getProvider()->hybridauthKey());
    }
}