<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class DataChangeLogDTO {

	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public int     $targetPersonId,
		public string  $fieldName,
		public ?string $oldValueEnc,
		public ?string $newValueEnc,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:             (int) $row['id'],
			actorUserId:    (int) $row['actor_user_id'],
			actorRole:      isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			targetPersonId: (int) $row['target_person_id'],
			fieldName:      (string) $row['field_name'],
			oldValueEnc:    isset( $row['old_value_enc'] ) ? (string) $row['old_value_enc'] : null,
			newValueEnc:    isset( $row['new_value_enc'] ) ? (string) $row['new_value_enc'] : null,
			createdAt:      (string) $row['created_at'],
		);
	}
}
