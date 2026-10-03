<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamRegistrationDTO {

	public function __construct(
		public int     $id,
		public int     $participationId,
		public int     $sessionId,
		public string  $status,
		public ?int    $activeSlot,
		public ?string $requestKey,
		public ?string $reason,
		public ?int    $actorUserId,
		public ?string $arrivedAt,
		public ?int    $arrivedByUserId,
		public string  $createdAt,
		public ?string $cancelledAt,
		public ?string $transferredAt,
		public ?string $missedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id               : (int) $row['id'],
			participationId  : (int) $row['participation_id'],
			sessionId        : (int) $row['session_id'],
			status           : (string) $row['status'],
			activeSlot       : isset( $row['active_slot'] ) ? (int) $row['active_slot'] : null,
			requestKey       : $row['request_key'] ?? null,
			reason           : $row['reason'] ?? null,
			actorUserId      : isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			arrivedAt        : $row['arrived_at'] ?? null,
			arrivedByUserId  : isset( $row['arrived_by_user_id'] ) ? (int) $row['arrived_by_user_id'] : null,
			createdAt        : (string) $row['created_at'],
			cancelledAt      : $row['cancelled_at'] ?? null,
			transferredAt    : $row['transferred_at'] ?? null,
			missedAt         : $row['missed_at'] ?? null,
		);
	}
}
