<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\DTO\Log\Events\LearningEvent;
use Inc\DTO\Log\LearningEventInputDTO;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;

class LearningEventWriter {

	public function __construct(
		private readonly LearningEventRepository $repository,
		private readonly ActorRoleResolver $roleResolver,
	) {}

	public function record( LearningEvent $event ): int {
		$role = $this->roleResolver->resolve( $event->actorUserId );

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

}
