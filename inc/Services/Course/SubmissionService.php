<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GradeDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\MediaManager;
use Inc\Managers\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

class SubmissionService {

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly EffectiveWorksResolver      $worksResolver,
		private readonly WorkManager                 $workManager,
		private readonly MediaManager                $mediaManager,
		private readonly LessonAccessPolicy          $accessPolicy,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
	) {}

	/**
	 * Ученик сдаёт работу.
	 *
	 * @param int     $studentPersonId
	 * @param int     $groupLessonId
	 * @param int     $workId
	 * @param int|null $taskId
	 * @param string  $answerText
	 * @param string|null $fileKey  Ключ в $_FILES (null = без файла).
	 * @return int submission_id
	 * @throws \InvalidArgumentException При нарушении правил.
	 * @throws \RuntimeException При проблемах с файлом.
	 */
	public function submit(
		int     $studentPersonId,
		int     $groupLessonId,
		int     $workId,
		?int    $taskId,
		string  $answerText,
		?string $fileKey = null,
	): int {
		if ( ! $this->accessPolicy->canSubmit( $studentPersonId, $groupLessonId ) ) {
			throw new \InvalidArgumentException( 'Сдача недоступна для данного ученика и урока.' );
		}

		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}

		$effectiveWorks = $this->worksResolver->resolve( $row );
		$workIds        = array_map( fn( $w ) => $w->id, $effectiveWorks );
		if ( ! in_array( $workId, $workIds, true ) ) {
			throw new \InvalidArgumentException( 'Работа не входит в эффективный набор урока.' );
		}

		$work = $this->workManager->get( $workId );
		if ( ! $work ) {
			throw new \InvalidArgumentException( 'Работа не найдена.' );
		}

		$dueAt = $row->homeworkDueAt;
		if ( ! $row->allowLate && null !== $dueAt ) {
			$now = $this->clock->now();
			if ( $now > $dueAt ) {
				throw new \InvalidArgumentException( 'Срок сдачи истёк, повторная сдача запрещена.' );
			}
		}

		$attachmentId = null;
		if ( null !== $fileKey ) {
			$attachmentId = $this->mediaManager->uploadFromRequest( $fileKey );
		}

		$existing = $this->submissions->findForWork( $studentPersonId, $groupLessonId, $workId, $taskId );

		if ( $existing ) {
			$this->submissions->update( $existing->id, array(
				'answer_text'  => $answerText,
				'attachment_id'=> $attachmentId ?? $existing->attachmentId,
				'status'       => 'submitted',
				'submitted_at' => $this->clock->now(),
			) );
			$submissionId = $existing->id;
		} else {
			$dto = new SubmissionInputDTO(
				studentPersonId : $studentPersonId,
				groupLessonId   : $groupLessonId,
				workId          : $workId,
				workType        : $work->workType->value,
				taskId          : $taskId,
				answerText      : $answerText,
				attachmentId    : $attachmentId,
				dueAt           : $dueAt,
				status          : 'submitted',
				submittedAt     : $this->clock->now(),
			);
			$submissionId = $this->submissions->create( $dto );
		}

		$this->dispatcher->dispatch(
			LogEvent::SubmissionMade,
			new LearningEvent(
				event      : LogEvent::SubmissionMade,
				actorUserId: $studentPersonId,
				groupId    : $row->groupId,
				entityType : 'submission',
				entityId   : (string) $submissionId,
				isPublic   : true,
			)
		);

		return $submissionId;
	}

	/** Преподаватель оценивает сдачу. */
	public function grade( int $submissionId, GradeDTO $grade, int $teacherUserId ): void {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub ) {
			throw new \InvalidArgumentException( 'Сдача не найдена.' );
		}

		$this->submissions->update( $submissionId, array(
			'status'            => $grade->status,
			'score'             => $grade->score,
			'max_score'         => $grade->maxScore,
			'feedback'          => $grade->feedback,
			'graded_by_user_id' => $teacherUserId,
			'graded_at'         => $this->clock->now(),
		) );

		$row = $this->groupLessons->find( $sub->groupLessonId );
		$this->dispatcher->dispatch(
			LogEvent::SubmissionGraded,
			new LearningEvent(
				event      : LogEvent::SubmissionGraded,
				actorUserId: $teacherUserId,
				groupId    : $row?->groupId,
				entityType : 'submission',
				entityId   : (string) $submissionId,
				isPublic   : true,
			)
		);
	}

	/** @return \Inc\DTO\Course\SubmissionDTO[] Сдачи ученика по уроку (для его кабинета). */
	public function getSubmissionsForView( int $studentPersonId, int $groupLessonId ): array {
		return $this->submissions->listByStudentAndGroupLesson( $studentPersonId, $groupLessonId );
	}

	/** Преподаватель возвращает на доработку. */
	public function returnForRework( int $submissionId, string $feedback, int $teacherUserId ): void {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub ) {
			throw new \InvalidArgumentException( 'Сдача не найдена.' );
		}

		$this->submissions->update( $submissionId, array(
			'status'            => 'returned',
			'feedback'          => $feedback,
			'graded_by_user_id' => $teacherUserId,
			'graded_at'         => $this->clock->now(),
		) );

		$row = $this->groupLessons->find( $sub->groupLessonId );
		$this->dispatcher->dispatch(
			LogEvent::SubmissionReturned,
			new LearningEvent(
				event      : LogEvent::SubmissionReturned,
				actorUserId: $teacherUserId,
				groupId    : $row?->groupId,
				entityType : 'submission',
				entityId   : (string) $submissionId,
				isPublic   : true,
			)
		);
	}
}
