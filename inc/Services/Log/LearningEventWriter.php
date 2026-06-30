<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\DTO\Log\Events\LearningEvent;
use Inc\DTO\Log\LearningEventInputDTO;
use Inc\Managers\Person\UserManager;
use Inc\Enums\Access\UserRole;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;

class LearningEventWriter {

	public function __construct(
		private readonly LearningEventRepository $repository,
		private readonly UserManager             $userManager,
	) {}

	public function record( LearningEvent $event ): int {
		$role = $this->resolveRole( $event->actorUserId );

		return $this->repository->create( new LearningEventInputDTO(
			action      : $event->event->value,
			subjectKey  : $event->subjectKey,
			groupId     : $event->groupId,
			actorUserId : $event->actorUserId,
			actorRole   : $role,
			entityType  : $event->entityType,
			entityId    : $event->entityId,
			isPublic    : $event->isPublic,
		) );
	}

	private function resolveRole( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}
		$user = $this->userManager->find( $userId );
		if ( ! $user ) {
			return null;
		}
		$roles = (array) ( $user->roles ?? array() );
		return empty( $roles ) ? null : UserRole::primarySlug( $roles );
	}
}
