<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\Enums\Assessment\AttemptStatus;

readonly class AttemptDTO {

	public function __construct(
		public int           $id,
		public int           $assessmentId,
		public ?int          $studentPersonId,
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
		public ?int          $groupLessonId = null,
		/** D18: учитель подтвердил результат — до этого ответы/баллы от ученика скрыты
		 * (см. {@see \Inc\Services\Assessment\AttemptRevealPolicy}). НЕ синоним
		 * `status === Graded`: у ЕГЭ (без ручной проверки) graded наступает сразу при
		 * сдаче, approvedAt требует отдельного явного действия учителя. */
		public ?string       $approvedAt = null,
		public ?int          $approvedByUserId = null,
		public ?int          $examParticipationId = null,
		public ?int          $examRegistrationId = null,
		public int           $resultVersion = 0,
	) {}

	/**
	 * @param string $now Текущее время в формате 'Y-m-d H:i:s' (источник — ClockInterface).
	 */
	public function isExpired( string $now ): bool {
		return $this->deadlineAt < $now;
	}

	/** Фактическое время решения в секундах (null — пока не сдана). */
	public function actualDurationSeconds(): ?int {
		if ( null === $this->submittedAt ) {
			return null;
		}
		return (int) ( strtotime( $this->submittedAt ) - strtotime( $this->startedAt ) );
	}

	public static function fromArray( array $row ): self {
		return new self(
			id                    : (int) $row['id'],
			assessmentId          : (int) $row['assessment_id'],
			studentPersonId       : isset( $row['student_person_id'] ) ? (int) $row['student_person_id'] : null,
			groupId               : isset( $row['group_id'] ) ? (int) $row['group_id'] : null,
			attemptNumber         : (int) $row['attempt_number'],
			startedAt             : (string) $row['started_at'],
			deadlineAt            : (string) $row['deadline_at'],
			submittedAt           : $row['submitted_at'] ?? null,
			status                : AttemptStatus::from( (string) ( $row['status'] ?? 'in_progress' ) ),
			totalScore            : isset( $row['total_score'] ) ? (float) $row['total_score'] : null,
			maxScore              : isset( $row['max_score'] ) ? (float) $row['max_score'] : null,
			gradedByUserId        : isset( $row['graded_by_user_id'] ) ? (int) $row['graded_by_user_id'] : null,
			createdAt             : (string) ( $row['created_at'] ?? '' ),
			updatedAt             : (string) ( $row['updated_at'] ?? '' ),
			groupLessonId         : isset( $row['group_lesson_id'] ) ? (int) $row['group_lesson_id'] : null,
			approvedAt            : $row['approved_at'] ?? null,
			approvedByUserId      : isset( $row['approved_by_user_id'] ) ? (int) $row['approved_by_user_id'] : null,
			examParticipationId   : isset( $row['exam_participation_id'] ) ? (int) $row['exam_participation_id'] : null,
			examRegistrationId    : isset( $row['exam_registration_id'] ) ? (int) $row['exam_registration_id'] : null,
			resultVersion         : (int) ( $row['result_version'] ?? 0 ),
		);
	}

	/** Учитель подтвердил результат — ответы/баллы можно показывать ученику (D18). */
	public function isApproved(): bool {
		return null !== $this->approvedAt;
	}

	/** Является ли попыткой экзамена (в отличие от обычной работы/контрольной). */
	public function isExam(): bool {
		return null !== $this->examParticipationId;
	}

	/** Копия без итога: баллы не уходят ученику, пока работа не раскрыта (7.5.2). */
	public function withoutTotals(): self {
		return new self(
			id                : $this->id,
			assessmentId      : $this->assessmentId,
			studentPersonId   : $this->studentPersonId,
			groupId           : $this->groupId,
			attemptNumber     : $this->attemptNumber,
			startedAt         : $this->startedAt,
			deadlineAt        : $this->deadlineAt,
			submittedAt       : $this->submittedAt,
			status            : $this->status,
			totalScore        : null,
			maxScore          : null,
			gradedByUserId    : $this->gradedByUserId,
			createdAt         : $this->createdAt,
			updatedAt         : $this->updatedAt,
			groupLessonId     : $this->groupLessonId,
			approvedAt        : $this->approvedAt,
			approvedByUserId  : $this->approvedByUserId,
			examParticipationId: $this->examParticipationId,
			examRegistrationId: $this->examRegistrationId,
			resultVersion     : $this->resultVersion,
		);
	}
}
