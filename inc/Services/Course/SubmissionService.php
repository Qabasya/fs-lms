<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\BatchCheckResultDTO;
use Inc\DTO\Course\GradeDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Course\AttemptSource;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;

class SubmissionService {

	/**
	 * Жёсткий потолок числа сдач одной работы одним учеником (Tasks.md, п. 8).
	 *
	 * У работы нет настройки попыток (в отличие от задачи-шага, где их задаёт
	 * `StepSettingsDTO::maxAttempts`), а «Пройти заново» перезаписывает сдачу —
	 * без потолка ученик может перебирать ответы бесконечно. Считаем раунды по
	 * `fs_lms_task_attempts` (там копится история пересдач, см. {@see recordWorkAttempt()}).
	 */
	public const int MAX_WORK_ATTEMPTS = 10;

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly EffectiveWorksResolver      $worksResolver,
		private readonly WorkManager                 $workManager,
		private readonly MediaManager                $mediaManager,
		private readonly LessonAccessPolicy          $accessPolicy,
		private readonly BatchCheckService           $batchChecker,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
		private readonly TaskAttemptRepository       $attempts,
	) {}


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

		$this->dispatchGraded( $sub->groupLessonId, $submissionId, $teacherUserId );
	}

	/** Событие «работа проверена» — общее для всех путей закрытия проверки. */
	private function dispatchGraded( int $groupLessonId, int $submissionId, int $teacherUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
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

	/**
	 * Ученик сдаёт работу пакетом (все ответы одной кнопкой).
	 *
	 * @param  array<int, mixed> $answers task_id => ответ (строка или массив для сложных типов)
	 * @param  array<int, float> $taskPoints task_id => вес (пусто → 1 на задачу)
	 * @return SubmissionDTO Агрегатная строка (task_id=null).
	 * @throws \InvalidArgumentException При нарушении правил доступа.
	 */
	public function submitBatch(
		int   $studentPersonId,
		int   $groupLessonId,
		int   $workId,
		array $answers,
		array $taskPoints = [],
	): SubmissionDTO {
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

		// T12.2 (D13): дедлайн per-work, иначе legacy homeworkDueAt занятия.
		$dueAt = $row->deadlineForWork( $workId );
		if ( ! $row->allowLate && null !== $dueAt && $this->clock->now() > $dueAt ) {
			throw new \InvalidArgumentException( 'Срок сдачи истёк.' );
		}

		// Tasks.md, п. 8: жёсткий потолок пересдач. Проверяем ДО записи попыток —
		// иначе лимит превышался бы ровно на одну сдачу.
		if ( $this->workAttemptsUsed( $studentPersonId, $groupLessonId, $workId ) >= self::MAX_WORK_ATTEMPTS ) {
			throw new \InvalidArgumentException(
				sprintf( 'Исчерпан лимит попыток сдачи (%d). Обратитесь к преподавателю.', self::MAX_WORK_ATTEMPTS )
			);
		}

		$result  = $this->batchChecker->check( $answers, $taskPoints );
		$now     = $this->clock->now();
		// Работа без ручных заданий проверена целиком автопроверкой — учителю в
		// ней делать нечего, поэтому сразу `graded`. Раньше такая сдача уходила
		// в `submitted` и застревала: в очереди проверки закрыть её было нечем
		// (кнопки нет), а в журнал/«Сводку по ученику» она не попадала вовсе —
		// те берут только оценённые строки (Tasks.md, п. 3 и 6).
		$status = $result->hasManual
			? SubmissionStatus::PendingReview->value
			: SubmissionStatus::Graded->value;

		foreach ( $answers as $taskId => $answer ) {
			$taskId     = (int) $taskId;
			$taskResult = $result->perTask[ $taskId ] ?? [ 'verdict' => 'pending', 'score' => 0.0, 'maxScore' => 1.0 ];
			$taskStatus = 'pending' === $taskResult['verdict']
				? SubmissionStatus::PendingReview->value
				: SubmissionStatus::Graded->value;

			$answerStored = is_array( $answer ) ? wp_json_encode( $answer ) : (string) $answer;

			// История пересдач: строка submissions ниже перезапишется, поэтому
			// каждая попытка отдельно копится в task_attempts (D-хвост Tasks.md).
			$this->recordWorkAttempt( $studentPersonId, $groupLessonId, $workId, $taskId, $answer, $taskResult );

			// Автопроверенное задание закрыто прямо сейчас; ручное ждёт учителя —
			// прошлая отметка о проверке (пересдача уже проверенной работы) снимается.
			$taskGradedAt = SubmissionStatus::Graded->value === $taskStatus ? $now : null;

			$existing = $this->submissions->findForWork( $studentPersonId, $groupLessonId, $workId, $taskId );
			if ( $existing ) {
				$this->submissions->update( $existing->id, [
					'answer_text'  => $answerStored,
					'score'        => $taskResult['score'],
					'max_score'    => $taskResult['maxScore'],
					'status'       => $taskStatus,
					'submitted_at' => $now,
					'graded_at'    => $taskGradedAt,
				] );
			} else {
				$this->submissions->create( new SubmissionInputDTO(
					studentPersonId : $studentPersonId,
					groupLessonId   : $groupLessonId,
					workId          : $workId,
					workType        : $work->workType->value,
					taskId          : $taskId,
					answerText      : $answerStored,
					dueAt           : $dueAt,
					status          : $taskStatus,
					submittedAt     : $now,
					gradedAt        : $taskGradedAt,
				) );
			}
		}

		$aggregate = $this->submissions->findAggregate( $studentPersonId, $groupLessonId, $workId );
		$verdicts  = wp_json_encode( $result->perTask );

		$gradedAt = SubmissionStatus::Graded->value === $status ? $now : null;

		if ( $aggregate ) {
			$this->submissions->update( $aggregate->id, [
				'answer_text'  => $verdicts,
				'score'        => (float) $result->correctCount,
				'max_score'    => (float) $result->totalCount,
				'status'       => $status,
				'submitted_at' => $now,
				'graded_at'    => $gradedAt,
			] );
			$aggregateId = $aggregate->id;
		} else {
			$aggregateId = $this->submissions->create( new SubmissionInputDTO(
				studentPersonId : $studentPersonId,
				groupLessonId   : $groupLessonId,
				workId          : $workId,
				workType        : $work->workType->value,
				taskId          : null,
				answerText      : $verdicts,
				dueAt           : $dueAt,
				status          : $status,
				submittedAt     : $now,
				gradedAt        : $gradedAt,
			) );
			$this->submissions->update( $aggregateId, [
				'score'     => (float) $result->correctCount,
				'max_score' => (float) $result->totalCount,
			] );
		}

		// entityId — id самой (агрегатной) сдачи, как и в single-пути submit(): значение
		// должно однозначно резолвиться через SubmissionRepository::find() независимо от
		// пути сдачи (нужно NotificationSubscriber::handleSubmissionMade() для фильтра
		// «ручная часть — нужна проверка»).
		$this->dispatcher->dispatch(
			LogEvent::SubmissionMade,
			new LearningEvent(
				event      : LogEvent::SubmissionMade,
				actorUserId: $studentPersonId,
				groupId    : $row->groupId,
				entityType : 'submission',
				entityId   : (string) $aggregateId,
				isPublic   : true,
			)
		);

		$updated = $this->submissions->findAggregate( $studentPersonId, $groupLessonId, $workId );
		assert( $updated !== null );
		return $updated;
	}

	/**
	 * Сколько раз ученик уже сдавал эту работу (Tasks.md, п. 8). Плеер читает
	 * это же число, чтобы предупредить о предпоследней и последней попытке.
	 */
	public function workAttemptsUsed( int $studentPersonId, int $groupLessonId, int $workId ): int {
		return $this->attempts->maxAttemptNumberByStep(
			$studentPersonId,
			$groupLessonId,
			AttemptSource::workStepKey( $workId )
		);
	}

	/**
	 * Пишет попытку по задаче работы в общую историю попыток.
	 *
	 * Номер считается ПО ЗАДАЧЕ, а не по работе целиком: в одной сдаче задач
	 * несколько, и сквозная нумерация давала бы задаче №2 номера 2, 4, 6…
	 *
	 * @param array{verdict: string, score: float, maxScore: float} $taskResult Итог проверки задачи
	 */
	private function recordWorkAttempt(
		int   $studentPersonId,
		int   $groupLessonId,
		int   $workId,
		int   $taskId,
		mixed $answer,
		array $taskResult
	): void {
		$stepKey = AttemptSource::workStepKey( $workId );

		$this->attempts->create(
			studentPersonId: $studentPersonId,
			groupLessonId  : $groupLessonId,
			stepKey        : $stepKey,
			taskId         : $taskId,
			attemptNumber  : $this->attempts->countByStepTask( $studentPersonId, $groupLessonId, $stepKey, $taskId ) + 1,
			answer         : $answer,
			isCorrect      : 'correct' === $taskResult['verdict'],
			score          : (float) $taskResult['score'],
			maxScore       : (float) $taskResult['maxScore'],
			itemFeedback   : array(),
		);
	}

	/**
	 * Преподаватель выставляет балл за конкретный ответ в пакетной сдаче.
	 * После оценки пересчитывает агрегат.
	 *
	 * @throws \InvalidArgumentException Если сдача не найдена или не является per-task строкой.
	 */
	public function gradeBatchTask( int $submissionId, float $score, string $feedback, int $teacherUserId ): void {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub || null === $sub->taskId ) {
			throw new \InvalidArgumentException( 'Per-task сдача не найдена.' );
		}

		$this->submissions->update( $submissionId, [
			'score'             => $score,
			'max_score'         => $sub->maxScore ?? 1.0,
			'feedback'          => $feedback,
			'status'            => SubmissionStatus::Graded->value,
			'graded_by_user_id' => $teacherUserId,
			'graded_at'         => $this->clock->now(),
		] );

		$this->recalculateAggregate( $sub->studentPersonId, $sub->groupLessonId, $sub->workId, $teacherUserId );
	}

	/**
	 * Преподаватель закрывает проверку работы целиком (Tasks.md, п. 6): сдача
	 * уезжает из «На проверке» в «Проверенные».
	 *
	 * Нужна там, где закрыть работу больше нечем: у сдачи с разбором по заданиям
	 * единой формы «Сохранить оценку» нет (оценивание поштучное, D4), а
	 * автопроверенная работа старых сдач так и осталась в `submitted`.
	 * Задания, которые преподаватель не оценил, фиксируются с текущим баллом
	 * (по умолчанию 0) — итог работы пересчитывается по ним же.
	 *
	 * @throws \InvalidArgumentException Если сдача не найдена или это per-task строка.
	 */
	public function completeReview( int $submissionId, int $teacherUserId ): void {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub || null !== $sub->taskId ) {
			throw new \InvalidArgumentException( 'Сдача не найдена.' );
		}

		$now         = $this->clock->now();
		$perTaskRows = $this->submissions->listPerTaskByStudentWorkLesson(
			$sub->studentPersonId,
			$sub->groupLessonId,
			$sub->workId
		);

		foreach ( $perTaskRows as $row ) {
			if ( SubmissionStatus::Graded === $row->status ) {
				continue;
			}
			$this->submissions->update( $row->id, array(
				'score'             => $row->score ?? 0.0,
				'max_score'         => $row->maxScore ?? 1.0,
				'status'            => SubmissionStatus::Graded->value,
				'graded_by_user_id' => $teacherUserId,
				'graded_at'         => $now,
			) );
		}

		if ( ! empty( $perTaskRows ) ) {
			$this->recalculateAggregate( $sub->studentPersonId, $sub->groupLessonId, $sub->workId, $teacherUserId );
			$this->dispatchGraded( $sub->groupLessonId, $submissionId, $teacherUserId );
			return;
		}

		// Свободный ответ без разбора на задачи — закрываем агрегат как есть.
		$this->submissions->update( $submissionId, array(
			'status'            => SubmissionStatus::Graded->value,
			'graded_by_user_id' => $teacherUserId,
			'graded_at'         => $now,
		) );
		$this->dispatchGraded( $sub->groupLessonId, $submissionId, $teacherUserId );
	}

	/** Пересчитывает агрегат после оценки одной или нескольких per-task строк. */
	private function recalculateAggregate( int $studentPersonId, int $groupLessonId, int $workId, int $teacherUserId ): void {
		$aggregate = $this->submissions->findAggregate( $studentPersonId, $groupLessonId, $workId );
		if ( ! $aggregate ) {
			return;
		}

		$perTaskRows = $this->submissions->listPerTaskByStudentWorkLesson( $studentPersonId, $groupLessonId, $workId );
		if ( empty( $perTaskRows ) ) {
			return;
		}

		$totalScore   = 0.0;
		$totalMax     = 0.0;
		$correctCount = 0;
		$hasPending   = false;

		foreach ( $perTaskRows as $row ) {
			$totalMax += $row->maxScore ?? 1.0;
			if ( $row->status === SubmissionStatus::PendingReview ) {
				$hasPending = true;
				continue;
			}
			$earned = $row->score ?? 0.0;
			$max    = $row->maxScore ?? 1.0;
			$totalScore += $earned;
			if ( $earned >= $max && $max > 0 ) {
				$correctCount++;
			}
		}

		$newStatus = $hasPending
			? SubmissionStatus::PendingReview->value
			: SubmissionStatus::Graded->value;

		$this->submissions->update( $aggregate->id, [
			'score'             => (float) $correctCount,
			'max_score'         => (float) count( $perTaskRows ),
			'status'            => $newStatus,
			'graded_by_user_id' => $teacherUserId,
			'graded_at'         => $this->clock->now(),
		] );
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
