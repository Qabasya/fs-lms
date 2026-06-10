<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class EmailLogDTO {

	public function __construct(
		public int     $id,
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $emailType,
		public ?int    $targetPersonId,
		public string  $status,
		public ?string $errorMessage,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:             (int) $row['id'],
			actorUserId:    isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole:      isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			emailType:      (string) $row['email_type'],
			targetPersonId: isset( $row['target_person_id'] ) ? (int) $row['target_person_id'] : null,
			status:         (string) $row['status'],
			errorMessage:   isset( $row['error_message'] ) ? (string) $row['error_message'] : null,
			createdAt:      (string) $row['created_at'],
		);
	}
}
