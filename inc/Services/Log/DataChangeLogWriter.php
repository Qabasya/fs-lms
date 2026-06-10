<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\DataChangeLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\DataChangeLogRepository;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;

class DataChangeLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly DataChangeLogRepository $repository,
		private readonly PiiCryptoService        $crypto,
		private readonly UserManager             $userManager,
		private readonly ClockInterface          $clock,
	) {}

	/**
	 * @param int         $targetPersonId Person ID чьи данные изменились
	 * @param string      $fieldName      Название поля
	 * @param string|null $oldValue       Старое значение в открытом виде (будет зашифровано)
	 * @param string|null $newValue       Новое значение в открытом виде (будет зашифровано)
	 */
	public function record( int $targetPersonId, string $fieldName, ?string $oldValue, ?string $newValue ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$oldEnc = null !== $oldValue && '' !== $oldValue ? $this->crypto->encrypt( $oldValue ) : null;
		$newEnc = null !== $newValue && '' !== $newValue ? $this->crypto->encrypt( $newValue ) : null;

		$this->repository->create( new DataChangeLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : 0,
			actorRole:      $role,
			targetPersonId: $targetPersonId,
			fieldName:      $fieldName,
			oldValueEnc:    $oldEnc,
			newValueEnc:    $newEnc,
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
