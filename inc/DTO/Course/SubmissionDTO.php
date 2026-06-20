<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;

readonly class SubmissionDTO {

	public function __construct(
		public int               $id,
		public int               $studentPersonId,
		public int               $groupLessonId,
		public int               $workId,
		public WorkType          $workType,
		public ?int              $taskId,
		public ?string           $answerText,
		public ?int              $attachmentId,
		public ?string           $dueAt,
		public SubmissionStatus  $status,
		public ?float            $score,
		public ?float            $maxScore,
		public ?string           $feedback,
		public ?int              $gradedByUserId,
		public ?string           $submittedAt,
		public ?string           $gradedAt,
		public string            $createdAt,
		public string            $updatedAt,
	) {}

	public function isLate(): bool {
		if ( null === $this->submittedAt || null === $this->dueAt ) {
			return false;
		}
		return $this->submittedAt > $this->dueAt;
	}

	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) $row['id'],
			studentPersonId : (int) $row['student_person_id'],
			groupLessonId   : (int) $row['group_lesson_id'],
			workId          : (int) $row['work_id'],
			workType        : WorkType::fromValueOrDefault( (string) ( $row['work_type'] ?? '' ) ),
			taskId          : isset( $row['task_id'] ) ? (int) $row['task_id'] : null,
			answerText      : $row['answer_text'] ?? null,
			attachmentId    : isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : null,
			dueAt           : $row['due_at'] ?? null,
			status          : SubmissionStatus::from( (string) ( $row['status'] ?? 'assigned' ) ),
			score           : isset( $row['score'] ) ? (float) $row['score'] : null,
			maxScore        : isset( $row['max_score'] ) ? (float) $row['max_score'] : null,
			feedback        : $row['feedback'] ?? null,
			gradedByUserId  : isset( $row['graded_by_user_id'] ) ? (int) $row['graded_by_user_id'] : null,
			submittedAt     : $row['submitted_at'] ?? null,
			gradedAt        : $row['graded_at'] ?? null,
			createdAt       : (string) ( $row['created_at'] ?? '' ),
			updatedAt       : (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
