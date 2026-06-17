<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

readonly class SubmissionInputDTO {

	public function __construct(
		public int     $studentPersonId,
		public int     $groupLessonId,
		public int     $workId,
		public string  $workType,
		public ?int    $taskId       = null,
		public ?string $answerText   = null,
		public ?int    $attachmentId = null,
		public ?string $dueAt        = null,
		public string  $status       = 'submitted',
		public ?string $submittedAt  = null,
	) {}

	public function toArray(): array {
		return array(
			'student_person_id' => $this->studentPersonId,
			'group_lesson_id'   => $this->groupLessonId,
			'work_id'           => $this->workId,
			'work_type'         => $this->workType,
			'task_id'           => $this->taskId,
			'answer_text'       => $this->answerText,
			'attachment_id'     => $this->attachmentId,
			'due_at'            => $this->dueAt,
			'status'            => $this->status,
			'submitted_at'      => $this->submittedAt,
		);
	}
}
