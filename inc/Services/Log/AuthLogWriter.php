<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\AuthLogInputDTO;
use Inc\Repositories\WPDBRepositories\AuthLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class AuthLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly AuthLogRepository $repository,
		private readonly ClockInterface    $clock,
	) {}

	/**
	 * @param string|null $loginIdentifier Логин/email попытки (только для login_failed — без пароля)
	 * @param string      $action          login/login_failed/otp_sent/otp_verified/password_reset
	 * @param bool        $success
	 */
	public function record( ?string $loginIdentifier, string $action, bool $success ): void {
		$ctx = $this->requestContext();

		$this->repository->create( new AuthLogInputDTO(
			loginIdentifier: $loginIdentifier,
			action:          $action,
			result:          $success ? 'success' : 'failure',
			actorIp:         $ctx->ip,
			actorUa:         '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:       $this->clock->now( 'mysql', true ),
		) );
	}
}
