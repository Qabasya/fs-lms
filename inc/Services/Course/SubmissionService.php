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
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Shared\CodedException;

class SubmissionService {

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
	 * ### Пересдача (.docs/Tasks.md, п. 1)
	 *
	 * Засчитанные задания ({@see self::lockedTaskIds()}) не перепроверяются: их строки,
	 * вердикты и оценки преподавателя остаются как есть, а ответы на них из запроса
	 * игнорируются. Проверяются только незачтённые — итог работы собирается из
	 * прежних вердиктов закрытых заданий и новых по остальным.
	 *
	 * @param  array<int, mixed> $answers task_id => ответ (строка или массив для сложных типов)
	 * @param  array<int, float> $taskPoints task_id => вес (пусто → 1 на задачу)
	 * @return SubmissionDTO Агрегатная строка (task_id=null).
	 * @throws CodedException При нарушении правил доступа, сроков и лимита попыток.
	 */
	public function submitBatch(
		int   $studentPersonId,
		int   $groupLessonId,
		int   $workId,
		array $answers,
		array $taskPoints = [],
	): SubmissionDTO {
		if ( ! $this->accessPolicy->canSubmit( $studentPersonId, $groupLessonId ) ) {
			throw new CodedException( ErrorCode::WorkAccess, 'Сдача недоступна для данного ученика и урока.' );
		}

		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new CodedException( ErrorCode::WorkNoLesson, 'Строка программы не найдена.' );
		}

		$effectiveWorks = $this->worksResolver->resolve( $row );
		$workIds        = array_map( fn( $w ) => $w->id, $effectiveWorks );
		if ( ! in_array( $workId, $workIds, true ) ) {
			throw new CodedException( ErrorCode::WorkNotInLesson, 'Работа не входит в эффективный набор урока.' );
		}

		$work = $this->workManager->get( $workId );
		if ( ! $work ) {
			throw new CodedException( ErrorCode::WorkNotFound, 'Работа не найдена.' );
		}

		// T12.2 (D13): дедлайн per-work, иначе legacy homeworkDueAt занятия.
		$dueAt = $row->deadlineForWork( $workId );
		if ( ! $row->allowLate && null !== $dueAt && $this->clock->now() > $dueAt ) {
			throw new CodedException( ErrorCode::WorkDeadline, 'Срок сдачи истёк.' );
		}

		$aggregate    = $this->submissions->findAggregate( $studentPersonId, $groupLessonId, $workId );
		$attemptsUsed = $this->attemptsUsedFrom( $aggregate, $studentPersonId, $groupLessonId, $workId );

		// Лимит — настройка работы (0 = без ограничений). Проверяем ДО записи попыток —
		// иначе лимит превышался бы ровно на одну сдачу.
		if ( $work->maxAttempts > 0 && $attemptsUsed >= $work->maxAttempts ) {
			throw new CodedException(
				ErrorCode::WorkLimit,
				sprintf( 'Исчерпан лимит попыток сдачи (%d). Обратитесь к преподавателю.', $work->maxAttempts )
			);
		}

		$previousVerdicts = $this->snapshotVerdicts( $aggregate );
		$locked           = null !== $aggregate
			? $this->lockedFrom( $previousVerdicts, $studentPersonId, $groupLessonId, $workId )
			: array();

		// Ответы на засчитанные задания не принимаем: их итог уже зафиксирован.
		$toCheck = array_diff_key( $answers, array_flip( $locked ) );
		if ( null !== $aggregate && array() === $toCheck ) {
			throw new CodedException( ErrorCode::WorkNothing, 'Все задания уже засчитаны — пересдавать нечего.' );
		}

		$result = $this->batchChecker->check( $toCheck, $taskPoints );
		$now    = $this->clock->now();

		foreach ( $toCheck as $taskId => $answer ) {
			$taskId     = (int) $taskId;
			$taskResult = $result->perTask[ $taskId ] ?? [ 'verdict' => 'pending', 'score' => 0.0, 'maxScore' => 1.0 ];
			$taskStatus = 'pending' === $taskResult['verdict']
				? SubmissionStatus::PendingReview->value
				: SubmissionStatus::Graded->value;

			$answerStored = is_array( $answer ) ? wp_json_encode( $answer ) : (string) $answer;

			// История пересдач: строка submissions ниже перезапишется, поэтому
			// каждая попытка отдельно копится в task_attempts (D-хвост Tasks.md).
			$this->recordWorkAttempt( $studentPersonId, $groupLessonId, $workId, $taskId, $answer, $taskResult, $attemptsUsed + 1 );

			// Автопроверенное задание закрыто прямо сейчас; ручное ждёт учителя —
			// прошлая отметка о проверке (пересдача уже проверенной работы) снимается.
			$taskGradedAt = SubmissionStatus::Graded->value === $taskStatus ? $now : null;

			$existing = $this->submissions->findForWork( $studentPersonId, $groupLessonId, $workId, $taskId );
			if ( $existing ) {
				$this->submissions->update( $existing->id, [
					'answer_text'       => $answerStored,
					'score'             => $taskResult['score'],
					'max_score'         => $taskResult['maxScore'],
					'status'            => $taskStatus,
					'submitted_at'      => $now,
					'graded_at'         => $taskGradedAt,
					// Новый ответ — прежняя оценка и отзыв преподавателя к нему не относятся.
					'graded_by_user_id' => null,
					'feedback'          => null,
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
					score           : (float) $taskResult['score'],
					maxScore        : (float) $taskResult['maxScore'],
				) );
			}
		}

		// Итог работы: прежние вердикты засчитанных заданий + свежие по остальным.
		$perTask = array_intersect_key( $previousVerdicts, array_flip( $locked ) ) + $result->perTask;
		ksort( $perTask );

		$correctCount = count( array_filter( $perTask, static fn( $v ) => 'correct' === ( $v['verdict'] ?? '' ) ) );
		$hasPending   = array() !== array_filter( $perTask, static fn( $v ) => 'pending' === ( $v['verdict'] ?? '' ) );

		// Работа без ручных заданий проверена целиком автопроверкой — учителю в
		// ней делать нечего, поэтому сразу `graded`. Раньше такая сдача уходила
		// в `submitted` и застревала: в очереди проверки закрыть её было нечем
		// (кнопки нет), а в журнал/«Сводку по ученику» она не попадала вовсе —
		// те берут только оценённые строки (Tasks.md, п. 3 и 6).
		$status   = $hasPending ? SubmissionStatus::PendingReview->value : SubmissionStatus::Graded->value;
		$gradedAt = SubmissionStatus::Graded->value === $status ? $now : null;
		$verdicts = wp_json_encode( $perTask );

		if ( $aggregate ) {
			$this->submissions->update( $aggregate->id, [
				'answer_text'   => $verdicts,
				'score'         => (float) $correctCount,
				'max_score'     => (float) count( $perTask ),
				'status'        => $status,
				'submitted_at'  => $now,
				'graded_at'     => $gradedAt,
				'attempt_count' => $attemptsUsed + 1,
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
				'score'         => (float) $correctCount,
				'max_score'     => (float) count( $perTask ),
				'attempt_count' => $attemptsUsed + 1,
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
	 * Сколько раз ученик уже сдавал эту работу. Плеер читает это же число, чтобы
	 * предупредить о предпоследней и последней попытке.
	 */
	public function workAttemptsUsed( int $studentPersonId, int $groupLessonId, int $workId ): int {
		return $this->attemptsUsedFrom(
			$this->submissions->findAggregate( $studentPersonId, $groupLessonId, $workId ),
			$studentPersonId,
			$groupLessonId,
			$workId
		);
	}

	/**
	 * Задания работы, закрытые для пересдачи:
	 *
	 * - засчитанные (вердикт «верно» — автопроверкой или преподавателем);
	 * - оценённые преподавателем (ручное задание проверено, автозадание пересчитано вручную):
	 *   иначе пересдача сбросила бы его оценку.
	 *
	 * @return int[] task_id
	 */
	public function lockedTaskIds( int $studentPersonId, int $groupLessonId, int $workId ): array {
		$aggregate = $this->submissions->findAggregate( $studentPersonId, $groupLessonId, $workId );
		if ( null === $aggregate ) {
			return array();
		}

		return $this->lockedFrom( $this->snapshotVerdicts( $aggregate ), $studentPersonId, $groupLessonId, $workId );
	}

	/**
	 * @param array<int, array<string, mixed>> $verdicts Снимок вердиктов агрегата
	 *
	 * @return int[]
	 */
	private function lockedFrom( array $verdicts, int $studentPersonId, int $groupLessonId, int $workId ): array {
		$locked = array();

		foreach ( $verdicts as $taskId => $verdict ) {
			if ( 'correct' === ( $verdict['verdict'] ?? '' ) ) {
				$locked[] = (int) $taskId;
			}
		}

		foreach ( $this->submissions->listPerTaskByStudentWorkLesson( $studentPersonId, $groupLessonId, $workId ) as $taskRow ) {
			if ( null !== $taskRow->taskId && SubmissionStatus::Graded === $taskRow->status && null !== $taskRow->gradedByUserId ) {
				$locked[] = $taskRow->taskId;
			}
		}

		return array_values( array_unique( $locked ) );
	}

	/**
	 * Счётчик сдач агрегата; у сдач до появления счётчика — номер последней попытки
	 * по истории заданий.
	 */
	private function attemptsUsedFrom( ?SubmissionDTO $aggregate, int $studentPersonId, int $groupLessonId, int $workId ): int {
		return max(
			$aggregate?->attemptCount ?? 0,
			$this->attempts->maxAttemptNumberByStep( $studentPersonId, $groupLessonId, AttemptSource::workStepKey( $workId ) )
		);
	}

	/**
	 * @return array<int, array<string, mixed>> task_id => вердикт из JSON-снимка агрегата
	 */
	private function snapshotVerdicts( ?SubmissionDTO $aggregate ): array {
		$decoded = null !== $aggregate ? json_decode( (string) $aggregate->answerText, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Пишет попытку по задаче работы в общую историю попыток.
	 *
	 * Номер попытки — номер сдачи работы (раунд), общий для всех заданий сдачи:
	 * при пересдаче перепроверяются только незачтённые задания, и у закрытого
	 * задания в поздних раундах записи нет — история преподавателя
	 * ({@see WorkDetailService::attemptHistory()}) подставляет его прежнюю попытку.
	 *
	 * @param int                                                    $round      Номер сдачи работы
	 * @param array{verdict: string, score: float, maxScore: float} $taskResult Итог проверки задачи
	 */
	private function recordWorkAttempt(
		int   $studentPersonId,
		int   $groupLessonId,
		int   $workId,
		int   $taskId,
		mixed $answer,
		array $taskResult,
		int   $round
	): void {
		$this->attempts->create(
			studentPersonId: $studentPersonId,
			groupLessonId  : $groupLessonId,
			stepKey        : AttemptSource::workStepKey( $workId ),
			taskId         : $taskId,
			attemptNumber  : $round,
			answer         : $answer,
			isCorrect      : 'correct' === $taskResult['verdict'],
			score          : (float) $taskResult['score'],
			maxScore       : (float) $taskResult['maxScore'],
			itemFeedback   : array(),
		);
	}

	/**
	 * Преподаватель выставляет балл за конкретный ответ в пакетной сдаче.
	 * После оценки синхронизирует снимок вердиктов и пересчитывает агрегат.
	 *
	 * Работает для ЛЮБОГО задания сдачи, не только для ручного шаблона: учитель
	 * должен уметь засчитать автопроверенную задачу, если ошибка была в условии
	 * (Tasks.md, п. 6) — не возвращая всю работу на доработку.
	 *
	 * @throws \InvalidArgumentException Если сдача не найдена или не является per-task строкой.
	 */
	public function gradeBatchTask( int $submissionId, float $score, string $feedback, int $teacherUserId ): void {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub || null === $sub->taskId ) {
			throw new \InvalidArgumentException( 'Per-task сдача не найдена.' );
		}

		$maxScore = $sub->maxScore ?? 1.0;

		$this->submissions->update( $submissionId, [
			'score'             => $score,
			'max_score'         => $maxScore,
			'feedback'          => $feedback,
			'status'            => SubmissionStatus::Graded->value,
			'graded_by_user_id' => $teacherUserId,
			'graded_at'         => $this->clock->now(),
		] );

		$this->syncAggregateSnapshot( $sub, $score, $maxScore );
		$this->recalculateAggregate( $sub->studentPersonId, $sub->groupLessonId, $sub->workId, $teacherUserId );
	}

	/**
	 * Переписывает вердикт одной задачи в JSON-снимке агрегатной строки.
	 *
	 * Снимок — то, что видит УЧЕНИК на экране результатов работы
	 * ({@see \Inc\Services\Course\LessonPlayerService::currentSubmission()} →
	 * `step-work.js::renderResults()`), и он остаётся авто-проверкой на момент
	 * сдачи. Без этой синхронизации засчитанная преподавателем задача
	 * пересчитывалась бы в журнале, но у ученика так и висела бы «Неверно».
	 *
	 * `correctOptionIds` и прочие ключи записи не трогаем — подсветка вариантов
	 * choice-задачи от вердикта не зависит.
	 */
	private function syncAggregateSnapshot( SubmissionDTO $sub, float $score, float $maxScore ): void {
		$aggregate = $this->submissions->findAggregate( $sub->studentPersonId, $sub->groupLessonId, $sub->workId );
		if ( ! $aggregate ) {
			return;
		}

		$perTask = json_decode( (string) $aggregate->answerText, true );
		if ( ! is_array( $perTask ) || ! isset( $perTask[ $sub->taskId ] ) || ! is_array( $perTask[ $sub->taskId ] ) ) {
			return;
		}

		$perTask[ $sub->taskId ]['verdict']  = ( $score >= $maxScore && $maxScore > 0 ) ? 'correct' : 'incorrect';
		$perTask[ $sub->taskId ]['score']    = $score;
		$perTask[ $sub->taskId ]['maxScore'] = $maxScore;

		$this->submissions->update( $aggregate->id, array( 'answer_text' => wp_json_encode( $perTask ) ) );
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
