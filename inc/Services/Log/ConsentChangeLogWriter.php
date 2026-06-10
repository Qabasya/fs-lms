<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\ConsentChangeLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ConsentChangeLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class ConsentChangeLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly ConsentChangeLogRepository $repository,
		private readonly UserManager                $userManager,
		private readonly ClockInterface             $clock,
	) {}

	/**
	 * @param int|null    $personId    Person ID кому принадлежит согласие (null при анонимном подписании)
	 * @param string      $consentType Тип согласия
	 * @param string|null $oldHash     Хеш документа до изменения
	 * @param string|null $newHash     Хеш документа после изменения
	 */
	public function record( ?int $personId, string $consentType, ?string $oldHash, ?string $newHash ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new ConsentChangeLogInputDTO(
			actorUserId: $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:   $role,
			personId:    $personId,
			consentType: $consentType,
			oldHash:     $oldHash,
			newHash:     $newHash,
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
