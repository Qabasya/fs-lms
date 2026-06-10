<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\DeletionLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\DeletionLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class DeletionLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly DeletionLogRepository $repository,
		private readonly UserManager           $userManager,
		private readonly ClockInterface        $clock,
	) {}

	/**
	 * @param string      $entityType      person/group/subject/period
	 * @param int         $entityId        ID удалённой сущности
	 * @param string|null $cascadedSummary Сводка каскадных удалений без PII
	 */
	public function record( string $entityType, int $entityId, ?string $cascadedSummary = null ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new DeletionLogInputDTO(
			actorUserId:     $ctx->actorUserId > 0 ? $ctx->actorUserId : 0,
			actorRole:       $role,
			entityType:      $entityType,
			entityId:        $entityId,
			cascadedSummary: $cascadedSummary,
			actorIp:         $ctx->ip,
			createdAt:       $this->clock->now( 'mysql', true ),
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
