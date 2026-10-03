<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamParticipationDTO {

	public function __construct(
		public int     $id,
		public int     $eventId,
		public int     $participantId,
		public string  $audience,
		public ?int    $activeRegistrationId,
		public ?int    $currentAttemptId,
		public ?int    $sourceId,
		public ?string $consentRefs,
		public bool    $transferAllowed,
		public ?string $admittedAt,
		public ?int    $admittedByUserId,
		public int     $version,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id                  : (int) $row['id'],
			eventId             : (int) $row['event_id'],
			participantId       : (int) $row['participant_id'],
			audience            : (string) $row['audience'],
			activeRegistrationId: isset( $row['active_registration_id'] ) ? (int) $row['active_registration_id'] : null,
			currentAttemptId    : isset( $row['current_attempt_id'] ) ? (int) $row['current_attempt_id'] : null,
			sourceId            : isset( $row['source_id'] ) ? (int) $row['source_id'] : null,
			consentRefs         : $row['consent_refs'] ?? null,
			transferAllowed     : (bool) $row['transfer_allowed'],
			admittedAt          : $row['admitted_at'] ?? null,
			admittedByUserId    : isset( $row['admitted_by_user_id'] ) ? (int) $row['admitted_by_user_id'] : null,
			version             : (int) $row['version'],
			createdAt           : (string) $row['created_at'],
			updatedAt           : (string) $row['updated_at'],
		);
	}
}
