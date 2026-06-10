<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class EnrollmentAuditLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly AuditLogRepository $repository,
		private readonly UserManager        $userManager,
		private readonly ClockInterface     $clock,
	) {}

	public function record( string $action, string $targetType, ?int $targetId, ?array $details = null ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( array(
			'actor_user_id' => $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			'actor_role'    => $role,
			'action'        => $action,
			'target_type'   => $targetType,
			'target_id'     => $targetId,
			'details_json'  => null !== $details ? wp_json_encode( $details ) : null,
			'actor_ip'      => $ctx->ip,
			'actor_ua'      => '' !== $ctx->userAgent ? $ctx->userAgent : null,
			'created_at'    => $this->clock->now( 'mysql', true ),
		) );
	}

	public function recordAnonymous( string $action, string $targetType, ?int $targetId, ?array $details = null ): void {
		$ctx = $this->requestContext();

		$this->repository->create( array(
			'actor_user_id' => null,
			'actor_role'    => null,
			'action'        => $action,
			'target_type'   => $targetType,
			'target_id'     => $targetId,
			'details_json'  => null !== $details ? wp_json_encode( $details ) : null,
			'actor_ip'      => $ctx->ip,
			'actor_ua'      => '' !== $ctx->userAgent ? $ctx->userAgent : null,
			'created_at'    => $this->clock->now( 'mysql', true ),
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
