<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Course\StepType;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\LessonProgressRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class LessonProgressService
 *
 * Прогресс прохождения шагов урока (★). Инлайн-шаги (text/video/task) ученик
 * отмечает явно (плеер → markViewed/markCompleted) — статус хранится в `fs_lms_lesson_progress`.
 * Завершение оцениваемых шагов (work/assessment) **не дублируется**: резолвится на чтение из
 * fact-таблиц (`submissions`/`attempts`). `isLessonCompleted` — все шаги урока пройдены.
 *
 * @package Inc\Services\Course
 */
class LessonProgressService {

	public function __construct(
		private readonly LessonProgressRepository    $progress,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessons,
		private readonly SubmissionRepository        $submissions,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ClockInterface              $clock,
	) {}

	/**
	 * Отметить шаг просмотренным (инлайн-контент в плеере).
	 */
	public function markViewed( int $studentPersonId, int $groupLessonId, string $stepKey ): void {
		$this->mark( $studentPersonId, $groupLessonId, $stepKey, ProgressStatus::Viewed, null );
	}

	/**
	 * Отметить шаг пройденным (инлайн / task-самопроверка). Для work/assessment «сделано»
	 * берётся из fact-таблиц и здесь не записывается как источник истины.
	 */
	public function markCompleted( int $studentPersonId, int $groupLessonId, string $stepKey ): void {
		$this->mark( $studentPersonId, $groupLessonId, $stepKey, ProgressStatus::Completed, $this->clock->now() );
	}

	/**
	 * Отметить шаг проваленным — все попытки задания исчерпаны без верного ответа (Этап 6).
	 */
	public function markFailed( int $studentPersonId, int $groupLessonId, string $stepKey ): void {
		$this->mark( $studentPersonId, $groupLessonId, $stepKey, ProgressStatus::Failed, null );
	}

	private function mark( int $studentPersonId, int $groupLessonId, string $stepKey, ProgressStatus $status, ?string $completedAt ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( null === $row ) {
			return;
		}

		if ( ! $row->lessonId ) {
			return;
		}
		$this->progress->upsert( $studentPersonId, $groupLessonId, $row->lessonId, $stepKey, $status, $completedAt );
	}

	/**
	 * Карта `stepKey => ProgressStatus` для плеера/дашборда. work/assessment резолвятся из
	 * fact-таблиц; инлайн/task — из таблицы прогресса (отсутствующие/гейтинг — `Available`).
	 *
	 * @return array<string, ProgressStatus>
	 */
	public function getStepStatuses( int $studentPersonId, int $groupLessonId ): array {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( null === $row ) {
			return array();
		}

		$lesson = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		if ( null === $lesson ) {
			return array();
		}

		$stored = array();
		foreach ( $this->progress->listForStudent( $studentPersonId, $groupLessonId ) as $p ) {
			$stored[ $p->stepKey ] = $p->status;
		}

		$completedWorkIds = $this->completedWorkIds( $studentPersonId, $groupLessonId );

		$result = array();
		foreach ( $lesson->steps as $step ) {
			$result[ $step->key ] = $this->resolveStatus( $step, $studentPersonId, $completedWorkIds, $stored[ $step->key ] ?? null );
		}

		return $result;
	}

	/**
	 * Урок пройден, когда все его шаги завершены. Урок без шагов завершённым не считается.
	 */
	public function isLessonCompleted( int $studentPersonId, int $groupLessonId ): bool {
		$statuses = $this->getStepStatuses( $studentPersonId, $groupLessonId );
		if ( empty( $statuses ) ) {
			return false;
		}

		foreach ( $statuses as $status ) {
			if ( ! $status->isComplete() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Резолвит статус шага. Для work/assessment «сделано» — ТОЛЬКО из fact-таблиц
	 * (сохранённый Completed для них игнорируется — не дублируем источник истины).
	 */
	private function resolveStatus( StepDTO $step, int $studentPersonId, array $completedWorkIds, ?ProgressStatus $stored ): ProgressStatus {
		if ( StepType::Work === $step->type ) {
			$workId = (int) ( $step->payload['ref'] ?? 0 );
			return in_array( $workId, $completedWorkIds, true ) ? ProgressStatus::Completed : $this->nonComplete( $stored );
		}

		if ( StepType::Assessment === $step->type ) {
			$assessmentId = (int) ( $step->payload['ref'] ?? 0 );
			return $this->hasPassedAttempt( $studentPersonId, $assessmentId ) ? ProgressStatus::Completed : $this->nonComplete( $stored );
		}

		// text / video / task — статус ставит ученик (плеер).
		return $stored ?? ProgressStatus::Available;
	}

	/** Сохранённый статус без «Completed» (для оцениваемых — completed только из fact-таблиц). */
	private function nonComplete( ?ProgressStatus $stored ): ProgressStatus {
		return ( null === $stored || $stored->isComplete() ) ? ProgressStatus::Available : $stored;
	}

	/**
	 * work_id, по которым у ученика есть завершённая (submitted/graded) сдача в этом уроке программы.
	 *
	 * @return int[]
	 */
	private function completedWorkIds( int $studentPersonId, int $groupLessonId ): array {
		$ids = array();
		foreach ( $this->submissions->listByStudentAndGroupLesson( $studentPersonId, $groupLessonId ) as $sub ) {
			if ( SubmissionStatus::Submitted === $sub->status || SubmissionStatus::Graded === $sub->status ) {
				$ids[] = $sub->workId;
			}
		}

		return $ids;
	}

	private function hasPassedAttempt( int $studentPersonId, int $assessmentId ): bool {
		if ( $assessmentId <= 0 ) {
			return false;
		}

		foreach ( $this->attempts->listByStudentAndAssessment( $studentPersonId, $assessmentId ) as $attempt ) {
			if ( AttemptStatus::Submitted === $attempt->status || AttemptStatus::Graded === $attempt->status ) {
				return true;
			}
		}

		return false;
	}
}
