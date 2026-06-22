<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

readonly class GroupLessonDTO {

	/**
	 * @param int      $id
	 * @param int      $groupId
	 * @param int      $lessonId
	 * @param int      $position
	 * @param int[]|null $workIdsSnapshot  NULL = урок ещё не публиковался (copy-on-publish)
	 * @param int[]    $extraWorkIds       доп. работы только для этой группы
	 * @param string|null $scheduledAt
	 * @param string|null $endsAt
	 * @param bool     $isPinned           фиксирует дату при reflow
	 * @param int|null $teacherUserId
	 * @param string   $visibility         hidden|open|archived
	 * @param string|null $openedAt
	 * @param string|null $homeworkDueAt
	 * @param bool     $allowLate
	 * @param string|null $recordingUrl
	 * @param int|null $createdByUserId
	 * @param int|null $updatedByUserId
	 */
	public function __construct(
		public int     $id,
		public int     $groupId,
		public ?int    $lessonId,
		public int     $position,
		public ?array  $workIdsSnapshot,
		public array   $extraWorkIds,
		public ?string $scheduledAt,
		public ?string $endsAt,
		public bool    $isPinned,
		public ?int    $teacherUserId,
		public string  $visibility,
		public ?string $openedAt,
		public ?string $homeworkDueAt,
		public bool    $allowLate,
		public ?string $recordingUrl,
		public ?int    $createdByUserId,
		public ?int    $updatedByUserId,
		public ?string $label = null,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) $row['id'],
			groupId         : (int) $row['group_id'],
			lessonId        : isset( $row['lesson_id'] ) ? (int) $row['lesson_id'] : null,
			position        : (int) $row['position'],
			workIdsSnapshot : isset( $row['work_ids_snapshot'] )
				? self::jsonIds( $row['work_ids_snapshot'] )
				: null,
			extraWorkIds    : self::jsonIds( $row['extra_work_ids'] ?? null ),
			scheduledAt     : $row['scheduled_at'] ?? null,
			endsAt          : $row['ends_at'] ?? null,
			isPinned        : (bool) ( $row['is_pinned'] ?? false ),
			teacherUserId   : isset( $row['teacher_user_id'] ) ? (int) $row['teacher_user_id'] : null,
			visibility      : (string) ( $row['visibility'] ?? 'hidden' ),
			openedAt        : $row['opened_at'] ?? null,
			homeworkDueAt   : $row['homework_due_at'] ?? null,
			allowLate       : (bool) ( $row['allow_late'] ?? true ),
			recordingUrl    : $row['recording_url'] ?? null,
			createdByUserId : isset( $row['created_by_user_id'] ) ? (int) $row['created_by_user_id'] : null,
			updatedByUserId : isset( $row['updated_by_user_id'] ) ? (int) $row['updated_by_user_id'] : null,
			label           : isset( $row['label'] ) && '' !== $row['label'] ? (string) $row['label'] : null,
		);
	}

	public function isPublished(): bool {
		return $this->workIdsSnapshot !== null;
	}

	private static function jsonIds( mixed $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded )
			? array_values( array_filter( array_map( 'intval', $decoded ) ) )
			: array();
	}
}
