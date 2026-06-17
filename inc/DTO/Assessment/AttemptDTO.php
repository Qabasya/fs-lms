<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\Enums\AttemptStatus;

readonly class AttemptDTO {

	public function __construct(
		public int           $id,
		public int           $assessmentId,
		public int           $studentPersonId,
		public ?int          $groupId,
		public int           $attemptNumber,
		public string        $startedAt,
		public string        $deadlineAt,
		public ?string       $submittedAt,
		public AttemptStatus $status,
		public ?float        $totalScore,
		public ?float        $maxScore,
		public ?int          $gradedByUserId,
		public string        $createdAt,
		public string        $updatedAt,
	) {}

	/**
	 * @param string $now Текущее время в формате 'Y-m-d H:i:s' (источник — ClockInterface).
	 */
	public function isExpired( string $now ): bool {
		return $this->deadlineAt < $now;
	}

	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) $row['id'],
			assessmentId    : (int) $row['assessment_id'],
			studentPersonId : (int) $row['student_person_id'],
			groupId         : isset( $row['group_id'] ) ? (int) $row['group_id'] : null,
			attemptNumber   : (int) $row['attempt_number'],
			startedAt       : (string) $row['started_at'],
			deadlineAt      : (string) $row['deadline_at'],
			submittedAt     : $row['submitted_at'] ?? null,
			status          : AttemptStatus::from( (string) ( $row['status'] ?? 'in_progress' ) ),
			totalScore      : isset( $row['total_score'] ) ? (float) $row['total_score'] : null,
			maxScore        : isset( $row['max_score'] ) ? (float) $row['max_score'] : null,
			gradedByUserId  : isset( $row['graded_by_user_id'] ) ? (int) $row['graded_by_user_id'] : null,
			createdAt       : (string) ( $row['created_at'] ?? '' ),
			updatedAt       : (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
