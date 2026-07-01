<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class ReviewQueueService
 *
 * Read-модель очереди «на проверку» (Эпик 3): сырые сдачи из
 * {@see SubmissionRepository::listQueueByGroup()} обогащаются отображаемым
 * именем ученика (снимок из `student_records`, НЕ шифрованный PII) и темой
 * занятия, чтобы преподаватель понимал, чью и какую работу он оценивает.
 *
 * @package Inc\Services\Course
 */
class ReviewQueueService {

	public function __construct(
		private readonly SubmissionRepository    $submissions,
		private readonly StudentRecordRepository $records,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
	) {}

	/**
	 * Очередь проверки группы (статус `submitted`), обогащённая для UI.
	 *
	 * @return array<int,array{
	 *   id:int, student_person_id:int, student_name:string,
	 *   work_id:int, work_type:string, work_type_label:string,
	 *   lesson_topic:string, status:string, answer_text:?string,
	 *   attachment_id:?int, max_score:?float, submitted_at:?string, is_late:bool
	 * }>
	 */
	public function forGroup( int $groupId ): array {
		$names  = $this->studentNames( $groupId );
		$topics = $this->lessonTopics( $groupId );

		return array_map(
			fn( $s ): array => array(
				'id'                => $s->id,
				'student_person_id' => $s->studentPersonId,
				'student_name'      => $names[ $s->studentPersonId ] ?? ( 'Ученик #' . $s->studentPersonId ),
				'work_id'           => $s->workId,
				'work_type'         => $s->workType->value,
				'work_type_label'   => $s->workType->label(),
				'lesson_topic'      => $topics[ $s->groupLessonId ] ?? '',
				'status'            => $s->status->value,
				'answer_text'       => $s->answerText,
				'attachment_id'     => $s->attachmentId,
				'max_score'         => $s->maxScore,
				'submitted_at'      => $s->submittedAt,
				'is_late'           => $s->isLate(),
			),
			$this->submissions->listQueueByGroup( $groupId )
		);
	}

	/**
	 * Карта personId → «Фамилия Имя» (снимок ростера, PII-safe для препода).
	 *
	 * @return array<int,string>
	 */
	private function studentNames( int $groupId ): array {
		$map = array();
		foreach ( $this->records->findActiveByGroupId( $groupId ) as $rec ) {
			$map[ $rec->studentPersonId ] = trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName );
		}
		return $map;
	}

	/**
	 * Карта group_lesson_id → тема занятия.
	 *
	 * @return array<int,string>
	 */
	private function lessonTopics( int $groupId ): array {
		$map = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			$lesson              = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
			$map[ (int) $row->id ] = $lesson?->topic ?? ( $row->label ?? '' );
		}
		return $map;
	}
}
