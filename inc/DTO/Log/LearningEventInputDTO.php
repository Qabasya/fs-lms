<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class LearningEventInputDTO {

	public function __construct(
		public string  $action,
		public ?string $subjectKey   = null,
		public ?int    $groupId      = null,
		public ?int    $actorUserId  = null,
		public ?string $actorRole    = null,
		public ?string $entityType   = null,
		public ?string $entityId     = null,
		public bool    $isPublic     = true,
	) {}

	public function toArray(): array {
		return array(
			'action'       => $this->action,
			'subject_key'  => $this->subjectKey,
			'group_id'     => $this->groupId,
			'actor_user_id' => $this->actorUserId,
			'actor_role'   => $this->actorRole,
			'entity_type'  => $this->entityType,
			'entity_id'    => $this->entityId,
			'is_public'    => (int) $this->isPublic,
		);
	}
}
