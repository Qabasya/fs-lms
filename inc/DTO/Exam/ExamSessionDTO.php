<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamSessionDTO {

	public function __construct(
		public int     $id,
		public int     $eventId,
		public int     $assessmentId,
		public string  $scheduledAt,
		public string  $plannedEndAt,
		public int     $roomId,
		public int     $capacity,
		public int     $occupiedCount,
		public int     $responsibleUserId,
		public string  $status,
		public ?string $firstStartedAt,
		public ?string $cancelReason,
		public int     $version,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id                  : (int) $row['id'],
			eventId             : (int) $row['event_id'],
			assessmentId        : (int) $row['assessment_id'],
			scheduledAt         : (string) $row['scheduled_at'],
			plannedEndAt        : (string) $row['planned_end_at'],
			roomId              : (int) $row['room_id'],
			capacity            : (int) $row['capacity'],
			occupiedCount       : (int) $row['occupied_count'],
			responsibleUserId   : (int) $row['responsible_user_id'],
			status              : (string) $row['status'],
			firstStartedAt      : $row['first_started_at'] ?? null,
			cancelReason        : $row['cancel_reason'] ?? null,
			version             : (int) $row['version'],
			createdAt           : (string) $row['created_at'],
			updatedAt           : (string) $row['updated_at'],
		);
	}
}
