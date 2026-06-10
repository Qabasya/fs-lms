<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\ExportLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ExportLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class ExportLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly ExportLogRepository $repository,
		private readonly UserManager         $userManager,
		private readonly ClockInterface      $clock,
	) {}

	/**
	 * @param string   $dataType     groups/students/parents/archive/log
	 * @param string   $actionType   single/bulk
	 * @param int[]    $targetIds    IDs затронутых записей
	 */
	public function record( string $dataType, string $actionType, array $targetIds = array() ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new ExportLogInputDTO(
			actorUserId:   $ctx->actorUserId > 0 ? $ctx->actorUserId : 0,
			actorRole:     $role,
			dataType:      $dataType,
			actionType:    $actionType,
			targetIdsJson: ! empty( $targetIds ) ? wp_json_encode( $targetIds ) : null,
			createdAt:     $this->clock->now( 'mysql', true ),
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
