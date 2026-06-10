<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\EmailLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\EmailLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

class EmailLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly EmailLogRepository $repository,
		private readonly UserManager        $userManager,
		private readonly ClockInterface     $clock,
	) {}

	/**
	 * @param string   $emailType      Кейс EmailTemplateType::value
	 * @param int|null $targetPersonId Person ID получателя (не email)
	 * @param bool     $success        Результат wp_mail()
	 * @param string   $errorMessage   Текст ошибки при неуспешной отправке
	 */
	public function record( string $emailType, ?int $targetPersonId, bool $success, string $errorMessage = '' ): void {
		$ctx  = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new EmailLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:      $role,
			emailType:      $emailType,
			targetPersonId: $targetPersonId,
			status:         $success ? 'success' : 'failed',
			errorMessage:   '' !== $errorMessage ? $errorMessage : null,
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
