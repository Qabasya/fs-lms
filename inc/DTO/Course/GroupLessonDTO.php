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
		/** @var array<string, array{max_attempts:int,shuffle:bool,hint_after_errors:int}>|null */
		public ?array  $stepSettingsOverrides = null,
		/** group|individual — тип занятия (D3). */
		public string  $kind = 'group',
		/** scheduled|held|cancelled|moved — план/факт (D3). */
		public string  $status = 'scheduled',
		/** Ученик индивидуального занятия (NULL для групповых). */
		public ?int    $studentPersonId = null,
		/** Кабинет занятия (override дефолта группы); NULL = кабинет группы. */
		public ?int    $roomId = null,
		/**
		 * Дедлайны работ занятия (T12.2, D13): work_id => 'Y-m-d H:i:s'. Per-work,
		 * приоритетнее legacy `$homeworkDueAt` (см. {@see self::deadlineForWork()}).
		 *
		 * @var array<int,string>
		 */
		public array   $workDeadlines = array(),
		/** Продолжение темы (T12.6, D14): id исходной строки, либо null для «родной». */
		public ?int    $continuedFromId = null,
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
			label                : isset( $row['label'] ) && '' !== $row['label'] ? (string) $row['label'] : null,
			stepSettingsOverrides: isset( $row['step_settings_overrides'] )
				? json_decode( (string) $row['step_settings_overrides'], true )
				: null,
			kind            : (string) ( $row['kind'] ?? 'group' ),
			status          : (string) ( $row['status'] ?? 'scheduled' ),
			studentPersonId : isset( $row['student_person_id'] ) ? (int) $row['student_person_id'] : null,
			roomId          : isset( $row['room_id'] ) && '' !== $row['room_id'] ? (int) $row['room_id'] : null,
			workDeadlines   : self::jsonDeadlines( $row['work_deadlines'] ?? null ),
			continuedFromId : isset( $row['continued_from_id'] ) && '' !== $row['continued_from_id'] ? (int) $row['continued_from_id'] : null,
		);
	}

	public function isPublished(): bool {
		return $this->workIdsSnapshot !== null;
	}

	/**
	 * Эффективный дедлайн работы (T12.2, D13): per-work дедлайн, иначе legacy
	 * `homeworkDueAt` занятия (фолбэк), иначе null (дедлайна нет).
	 */
	public function deadlineForWork( int $workId ): ?string {
		return $this->workDeadlines[ $workId ] ?? $this->homeworkDueAt;
	}

	/** @return array<int,string> */
	private static function jsonDeadlines( mixed $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $workId => $deadline ) {
			if ( is_string( $deadline ) && '' !== $deadline ) {
				$out[ (int) $workId ] = $deadline;
			}
		}
		return $out;
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
