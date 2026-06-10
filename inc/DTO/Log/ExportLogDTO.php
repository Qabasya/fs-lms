<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class ExportLogDTO {

	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $dataType,
		public string  $actionType,
		public ?string $targetIdsJson,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:            (int) $row['id'],
			actorUserId:   (int) $row['actor_user_id'],
			actorRole:     isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			dataType:      (string) $row['data_type'],
			actionType:    (string) $row['action_type'],
			targetIdsJson: isset( $row['target_ids_json'] ) ? (string) $row['target_ids_json'] : null,
			createdAt:     (string) $row['created_at'],
		);
	}
}
