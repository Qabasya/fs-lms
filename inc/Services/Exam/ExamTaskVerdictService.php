<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Exam\TaskVerdict;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;

/**
 * Вычисление вердиктов задач экзамена (7.2).
 *
 * @package Inc\Services\Exam
 */
class ExamTaskVerdictService {

	public function __construct(
		private readonly AssessmentAnswerRepository $answerRepo,
		private readonly AssessmentManager $assessments,
	) {}

	/**
	 * Вердикты для всех задач попытки в порядке работы (7.2.1).
	 *
	 * @param AttemptDTO $attempt Попытка
	 * @param AttemptAnswerDTO[] $answers Ответы в любом порядке
	 *
	 * @return array{taskId: int, verdict: TaskVerdict}[] В порядке taskIds работы
	 */
	public function forAttempt( AttemptDTO $attempt, array $answers = array() ): array {
		$assessment = $this->assessments->get( $attempt->assessmentId );
		if ( ! $assessment ) {
			return array();
		}

		// Если ответы не переданы, загрузить их
		if ( empty( $answers ) ) {
			$answers = $this->answerRepo->listByAttempt( $attempt->id );
		}

		// Индекс по taskId
		$answersByTask = array();
		foreach ( $answers as $answer ) {
			$answersByTask[ $answer->taskId ] = $answer;
		}

		// Есть ли хотя бы одно задание на ручной проверке
		$hasPendingManual = $assessment->hasManual && 'in_progress' === $attempt->status;

		$result = array();
		foreach ( $assessment->taskIds as $taskId ) {
			$taskId = (int) $taskId;
			$answer = $answersByTask[ $taskId ] ?? null;

			$result[] = array(
				'taskId' => $taskId,
				'verdict' => $this->verdictFor( $answer, $hasPendingManual ),
			);
		}

		return $result;
	}

	/**
	 * Вердикт для одного ответа (7.2.2).
	 */
	private function verdictFor( ?AttemptAnswerDTO $answer, bool $hasPendingManual ): TaskVerdict {
		// Нет ответа — не отвечено
		if ( ! $answer || empty( $answer->answerText ) ) {
			return TaskVerdict::Unanswered;
		}

		// Если есть ручная проверка — pending или partial в зависимости от статуса
		if ( $answer->isCorrect === null ) {
			// Ещё не проверено
			return TaskVerdict::Pending;
		}

		// Проверено: правильно / неправильно
		if ( $answer->isCorrect ) {
			// Правильно: может быть partial если не полный балл
			if ( $answer->score !== null && $answer->maxScore !== null ) {
				if ( $answer->score < $answer->maxScore ) {
					return TaskVerdict::Partial;
				}
			}
			return TaskVerdict::Correct;
		}

		// Неправильно
		return TaskVerdict::Incorrect;
	}

	/**
	 * Худший вердикт из массива (для блокировки по одному дефекту) (7.2.3).
	 * Порядок (худший -> лучший): pending > unanswered/incorrect > partial > correct
	 */
	public function worst( array $verdicts ): TaskVerdict {
		$priority = array(
			TaskVerdict::Pending->value     => 4,
			TaskVerdict::Unanswered->value  => 3,
			TaskVerdict::Incorrect->value   => 3,
			TaskVerdict::Partial->value     => 2,
			TaskVerdict::Correct->value     => 1,
		);

		$worstVerdict = TaskVerdict::Correct;
		$worstPriority = 1;

		foreach ( $verdicts as $verdict ) {
			if ( ! $verdict instanceof TaskVerdict ) {
				$verdict = TaskVerdict::from( $verdict );
			}

			$p = $priority[ $verdict->value ] ?? 0;
			if ( $p > $worstPriority ) {
				$worstPriority = $p;
				$worstVerdict = $verdict;
			}
		}

		return $worstVerdict;
	}

	/**
	 * Статус блока заданий (7.2.4).
	 * Используется в ExamScoreService::units().
	 *
	 * @param array{verdict: TaskVerdict}[] $unitTasks Вердикты задач блока
	 */
	public function statusForUnit( array $unitTasks ): TaskVerdict {
		$verdicts = array_map(
			static fn( $t ) => $t['verdict'] instanceof TaskVerdict ? $t['verdict'] : TaskVerdict::from( $t['verdict'] ),
			$unitTasks
		);

		return $this->worst( $verdicts );
	}
}
