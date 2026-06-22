<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

readonly class GroupLessonInputDTO {

	public function __construct(
		public int     $groupId,
		public ?int    $lessonId,
		public int     $position,
		public ?array  $workIdsSnapshot  = null,
		public array   $extraWorkIds     = array(),
		public ?string $scheduledAt      = null,
		public ?string $endsAt           = null,
		public bool    $isPinned         = false,
		public ?int    $teacherUserId    = null,
		public string  $visibility       = 'hidden',
		public ?string $openedAt         = null,
		public ?string $homeworkDueAt    = null,
		public bool    $allowLate        = true,
		public ?string $recordingUrl     = null,
		public ?int    $createdByUserId  = null,
		public ?string $label            = null,
	) {}

	public function toArray(): array {
		return array(
			'group_id'          => $this->groupId,
			'lesson_id'         => $this->lessonId,
			'position'          => $this->position,
			'work_ids_snapshot' => null !== $this->workIdsSnapshot
				? json_encode( $this->workIdsSnapshot )
				: null,
			'extra_work_ids'    => json_encode( $this->extraWorkIds ),
			'scheduled_at'      => $this->scheduledAt,
			'ends_at'           => $this->endsAt,
			'is_pinned'         => (int) $this->isPinned,
			'teacher_user_id'   => $this->teacherUserId,
			'visibility'        => $this->visibility,
			'opened_at'         => $this->openedAt,
			'homework_due_at'   => $this->homeworkDueAt,
			'allow_late'        => (int) $this->allowLate,
			'recording_url'     => $this->recordingUrl,
			'created_by_user_id' => $this->createdByUserId,
			'label'             => $this->label,
		);
	}
}
