<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class AttendanceDTO
 *
 * Одна отметка посещаемости: (занятие, ученик) → присутствовал/отсутствовал.
 * Бинарная модель (D4): без late/excused, без баллов.
 *
 * @package Inc\DTO\Course
 */
final readonly class AttendanceDTO {

	public function __construct(
		public int     $id,
		public int     $groupLessonId,
		public int     $studentPersonId,
		public bool    $isPresent,
		public ?int    $markedBy,
		public string  $markedAt,
	) {}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function fromArray( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['group_lesson_id'] ?? 0 ),
			(int) ( $row['student_person_id'] ?? 0 ),
			(bool) (int) ( $row['is_present'] ?? 1 ),
			isset( $row['marked_by'] ) ? (int) $row['marked_by'] : null,
			(string) ( $row['marked_at'] ?? '' ),
		);
	}
}
