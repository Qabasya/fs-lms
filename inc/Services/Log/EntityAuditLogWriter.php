<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\EntityAuditLogInputDTO;
use Inc\Enums\EntityType;
use Inc\Enums\OperationType;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\EntityAuditLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class EntityAuditLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly EntityAuditLogRepository $repository,
		private readonly UserManager              $userManager,
		private readonly ClockInterface           $clock,
	) {}

	public function record(
		int           $actorUserId,
		OperationType $operation,
		EntityType    $entityType,
		?int          $entityId,
		?string       $oldLabel = null
	): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $actorUserId );

		$this->repository->create( new EntityAuditLogInputDTO(
			actorUserId: $actorUserId > 0 ? $actorUserId : null,
			actorRole:   $role,
			operation:   $operation,
			entityType:  $entityType,
			entityId:    $entityId,
			oldLabel:    $oldLabel,
			actorIp:     $ctx->ip,
			createdAt:   $this->clock->now( 'mysql', true ),
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
