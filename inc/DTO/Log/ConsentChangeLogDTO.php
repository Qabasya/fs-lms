<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class ConsentChangeLogDTO {

	public function __construct(
		public int     $id,
		public ?int    $actorUserId,
		public ?string $actorRole,
		public ?int    $personId,
		public string  $consentType,
		public ?string $oldHash,
		public ?string $newHash,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:          (int) $row['id'],
			actorUserId: isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole:   isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			personId:    isset( $row['person_id'] ) ? (int) $row['person_id'] : null,
			consentType: (string) $row['consent_type'],
			oldHash:     isset( $row['old_hash'] ) ? (string) $row['old_hash'] : null,
			newHash:     isset( $row['new_hash'] ) ? (string) $row['new_hash'] : null,
			createdAt:   (string) $row['created_at'],
		);
	}
}
