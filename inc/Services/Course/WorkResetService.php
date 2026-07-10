<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Shared\PluginLogger;

/**
 * Class WorkResetService
 *
 * Сброс попыток ученика преподавателем (задача 11): по `source_type` из
 * GradebookEntryDTO удаляет все сдачи/попытки ученика по конкретной работе/экзамену
 * (с результатами), чтобы ученик мог пройти заново.
 *  - `attempt`    — все попытки экзамена ученика по assessment_id + их ответы.
 *  - `submission` — все сдачи ученика по работе занятия (агрегат + per-task строки).
 *
 * Проверка доступа (canWriteJournal) — в колбэке; сервис только резолвит группу и удаляет.
 *
 * @package Inc\Services\Course
 */
class WorkResetService {

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly GroupLessonRepository       $groupLessons,
	) {}

	/**
	 * Группа работы/экзамена по источнику — для проверки доступа перед сбросом.
	 * null, если источник не найден / тип неизвестен.
	 */
	public function groupIdFor( string $sourceType, int $sourceId ): ?int {
		return match ( $sourceType ) {
			'attempt'    => $this->attempts->find( $sourceId )?->groupId,
			'submission' => $this->groupIdOfSubmission( $sourceId ),
			default      => null,
		};
	}

	/**
	 * Удаляет все попытки/сдачи ученика по этой работе/экзамену. Возвращает число
	 * удалённых строк (>= 0); -1 — источник не найден / тип неизвестен.
	 */
	public function reset( string $sourceType, int $sourceId ): int {
		return match ( $sourceType ) {
			'attempt'    => $this->resetAttempt( $sourceId ),
			'submission' => $this->resetSubmission( $sourceId ),
			default      => -1,
		};
	}

	private function resetAttempt( int $attemptId ): int {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt ) {
			return -1;
		}

		$deleted = 0;
		foreach ( $this->attempts->listByStudentAndAssessment( $attempt->studentPersonId, $attempt->assessmentId ) as $a ) {
			$this->answers->deleteByAttempt( $a->id );
			if ( $this->attempts->delete( $a->id ) ) {
				++$deleted;
			}
		}

		PluginLogger::warning(
			'WorkReset',
			'Сброс попыток экзамена преподавателем',
			array(
				'assessment_id'     => $attempt->assessmentId,
				'student_person_id' => $attempt->studentPersonId,
				'attempts_deleted'  => $deleted,
			)
		);

		return $deleted;
	}

	private function resetSubmission( int $submissionId ): int {
		$sub = $this->submissions->find( $submissionId );
		if ( null === $sub ) {
			return -1;
		}

		$deleted = $this->submissions->deleteAllByStudentWorkLesson(
			$sub->studentPersonId,
			$sub->groupLessonId,
			$sub->workId
		);

		PluginLogger::warning(
			'WorkReset',
			'Сброс сдач работы преподавателем',
			array(
				'work_id'           => $sub->workId,
				'group_lesson_id'   => $sub->groupLessonId,
				'student_person_id' => $sub->studentPersonId,
				'rows_deleted'      => $deleted,
			)
		);

		return $deleted;
	}

	private function groupIdOfSubmission( int $submissionId ): ?int {
		$sub = $this->submissions->find( $submissionId );
		if ( null === $sub ) {
			return null;
		}
		return $this->groupLessons->find( $sub->groupLessonId )?->groupId;
	}
}
