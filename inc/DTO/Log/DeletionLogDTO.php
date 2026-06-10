<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class DeletionLogDTO {

	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $entityType,
		public int     $entityId,
		public ?string $cascadedSummary,
		public string  $actorIp,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:               (int) $row['id'],
			actorUserId:      (int) $row['actor_user_id'],
			actorRole:        isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			entityType:       (string) $row['entity_type'],
			entityId:         (int) $row['entity_id'],
			cascadedSummary:  isset( $row['cascaded_summary'] ) ? (string) $row['cascaded_summary'] : null,
			actorIp:          (string) $row['actor_ip'],
			createdAt:        (string) $row['created_at'],
		);
	}
}
