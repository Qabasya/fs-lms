<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\LessonProgressRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Shared\Traits\TransactionRunner;

class GroupDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly GroupsRepository $groups,
		private readonly GroupLessonRepository $groupLessons,
		private readonly AttendanceRepository $attendance,
		private readonly SubmissionRepository $submissions,
		private readonly LessonProgressRepository $lessonProgress,
		private readonly TaskAttemptRepository $taskAttempts,
		private readonly LearningEventRepository $learningEvents,
		private readonly AssessmentAttemptRepository $assessmentAttempts,
		private readonly AssessmentAnswerRepository $assessmentAnswers,
		private readonly SubstitutionRepository $substitutions,
		private readonly DeletionEventDispatcher    $dispatcher,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function handle( DeleteGroupEvent $event ): void {
		$groupId = $event->groupId;
		$actorId = $event->actorId;

		$affected = $this->inTransaction( function () use ( $groupId ) {
			// Реальных FK в схеме нет — осиротевшие group_lessons/attendance/submissions/
			// lesson_progress/task_attempts/learning_events/assessment_attempts/substitutions
			// вычищаем вручную. Сперва дочерние по group_lesson_id, затем по group_id.
			foreach ( $this->groupLessons->listByGroup( $groupId ) as $lesson ) {
				$this->attendance->deleteAllByGroupLesson( $lesson->id );
				$this->submissions->deleteAllByGroupLesson( $lesson->id );
				$this->lessonProgress->deleteByGroupLesson( $lesson->id );
				$this->taskAttempts->deleteAllByGroupLesson( $lesson->id );
			}
			$this->groupLessons->deleteAllByGroup( $groupId );

			foreach ( $this->assessmentAttempts->listIdsByGroup( $groupId ) as $attemptId ) {
				$this->assessmentAnswers->deleteByAttempt( $attemptId );
			}
			$this->assessmentAttempts->deleteAllByGroup( $groupId );

			$this->learningEvents->deleteAllByGroup( $groupId );
			$this->substitutions->deleteAllByGroup( $groupId );

			$ids = $this->studentRecords->deleteAllByGroupAndCollect( $groupId );
			$this->groups->hardDelete( $groupId );
			return $ids;
		} );

		$studentsCount = count( $affected['students'] ?? array() );
		$parentsCount  = count( $affected['parents'] ?? array() );
		$this->logEvents->dispatch(
			LogEvent::EntityHardDeleted,
			new EntityHardDeletedEvent( $actorId, 'group', $groupId, "students:{$studentsCount}, parents:{$parentsCount}" )
		);

		foreach ( $affected['students'] as $studentPersonId ) {
			$this->dispatcher->dispatch(
				new StudentRecordsRemovedFromGroupEvent( $studentPersonId, $actorId )
			);
		}

		foreach ( $affected['parents'] as $parentPersonId ) {
			$this->dispatcher->dispatch(
				new ParentRecordsRemovedFromGroupEvent( $parentPersonId, $actorId )
			);
		}
	}
}
