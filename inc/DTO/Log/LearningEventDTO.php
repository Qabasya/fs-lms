<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class LearningEventDTO {

	public function __construct(
		public int     $id,
		public ?string $subjectKey,
		public ?int    $groupId,
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $action,
		public ?string $entityType,
		public ?string $entityId,
		public bool    $isPublic,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id          : (int) $row['id'],
			subjectKey  : $row['subject_key'] ?? null,
			groupId     : isset( $row['group_id'] ) ? (int) $row['group_id'] : null,
			actorUserId : isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole   : $row['actor_role'] ?? null,
			action      : (string) $row['action'],
			entityType  : $row['entity_type'] ?? null,
			entityId    : $row['entity_id'] ?? null,
			isPublic    : (bool) $row['is_public'],
			createdAt   : (string) $row['created_at'],
		);
	}
}
