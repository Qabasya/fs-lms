<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Course\ProgressStatus;

/**
 * Прохождение одного шага урока учеником (строка `fs_lms_lesson_progress`, ★).
 *
 * @package Inc\DTO\Course
 */
readonly class LessonProgressDTO {

	public function __construct(
		public int            $id,
		public int            $studentPersonId,
		public int            $groupLessonId,
		public int            $lessonId,
		public string         $stepKey,
		public ProgressStatus $status,
		public ?string        $completedAt,
		public string         $createdAt,
		public string         $updatedAt,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) ( $row['id'] ?? 0 ),
			studentPersonId : (int) ( $row['student_person_id'] ?? 0 ),
			groupLessonId   : (int) ( $row['group_lesson_id'] ?? 0 ),
			lessonId        : (int) ( $row['lesson_id'] ?? 0 ),
			stepKey         : (string) ( $row['step_key'] ?? '' ),
			status          : ProgressStatus::fromValueOrDefault( (string) ( $row['status'] ?? 'locked' ) ),
			completedAt     : isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			createdAt       : (string) ( $row['created_at'] ?? '' ),
			updatedAt       : (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
