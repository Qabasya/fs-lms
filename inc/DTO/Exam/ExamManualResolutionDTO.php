<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamManualResolutionDTO {

	public function __construct(
		public int     $id,
		public ?int    $applicationId,
		public ?int    $participationId,
		public string  $kind,
		public string  $reason,
		public int     $actorUserId,
		public ?string $amount,
		public ?int    $oldSessionId,
		public ?int    $newSessionId,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) $row['id'],
			applicationId   : isset( $row['application_id'] ) ? (int) $row['application_id'] : null,
			participationId : isset( $row['participation_id'] ) ? (int) $row['participation_id'] : null,
			kind            : (string) $row['kind'],
			reason          : (string) $row['reason'],
			actorUserId     : (int) $row['actor_user_id'],
			amount          : $row['amount'] ?? null,
			oldSessionId    : isset( $row['old_session_id'] ) ? (int) $row['old_session_id'] : null,
			newSessionId    : isset( $row['new_session_id'] ) ? (int) $row['new_session_id'] : null,
			createdAt       : (string) $row['created_at'],
		);
	}
}
