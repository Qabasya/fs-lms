<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Person\PiiAccessLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class PiiAccessLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly PiiAccessLogRepository $repository,
		private readonly UserManager            $userManager,
		private readonly ClockInterface         $clock,
	) {}

	public function record( int $personId, string $fieldsAccessed, string $accessReason ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new PiiAccessLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:      $role,
			personId:       $personId,
			fieldsAccessed: $fieldsAccessed,
			accessReason:   $accessReason,
			actorIp:        $ctx->ip,
			actorUa:        '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:      $this->clock->now( 'mysql', true ),
		) );
	}

	private function resolveRole( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}
		$user = $this->userManager->find( $userId );
		if ( null === $user || empty( $user->roles ) ) {
			return null;
		}
		return (string) reset( $user->roles );
	}
}
