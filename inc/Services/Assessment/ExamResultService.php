<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\ExamResultDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

/**
 * Class ExamResultService
 *
 * Формирует результат экзамена для показа ученику (T7.10, T7.14).
 * Правильные ответы не передаются — только вердикты correct/incorrect/pending.
 * Для ЕГЭ: первичный балл переводится во вторичный через SecondaryScoreService.
 *
 * @package Inc\Services\Assessment
 */
class ExamResultService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly SecondaryScoreService       $secondaryScore,
	) {}

	/**
	 * Строит результат для экрана ученика.
	 *
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 */
	public function buildForStudent( int $attemptId, int $studentPersonId ): ExamResultDTO {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		$assessment = $this->assessments->get( $attempt->assessmentId );
		$kind       = $assessment ? $assessment->kind : AssessmentKind::Control;
		$passScore  = $assessment ? $assessment->passScore : 0.0;

		$answerList = $this->answers->listByAttempt( $attemptId );

		$correctCount = 0;
		$totalCount   = count( $answerList );
		$perTask      = [];

		foreach ( $answerList as $answer ) {
			$verdict = $this->verdictFrom( $answer );
			if ( 'correct' === $verdict ) {
				$correctCount++;
			}
			$perTask[ $answer->taskId ] = [ 'verdict' => $verdict ];
		}

		$primaryScore    = $attempt->totalScore ?? 0.0;
		$maxPrimaryScore = $attempt->maxScore   ?? (float) $totalCount;

		$passed = $passScore > 0
			? $primaryScore >= $passScore
			: $correctCount === $totalCount;

		$secondaryScore = null;
		if ( $kind->needsSecondaryScore() && $assessment ) {
			$secondaryScore = $this->secondaryScore->translate( $primaryScore, $assessment->scoreMap );
		}

		return new ExamResultDTO(
			attemptId             : $attemptId,
			kind                  : $kind,
			correctCount          : $correctCount,
			totalCount            : $totalCount,
			primaryScore          : $primaryScore,
			maxPrimaryScore       : $maxPrimaryScore,
			secondaryScore        : $secondaryScore,
			passed                : $passed,
			actualDurationSeconds : $attempt->actualDurationSeconds(),
			perTask               : $perTask,
		);
	}

	private function verdictFrom( AttemptAnswerDTO $answer ): string {
		if ( null === $answer->isCorrect ) {
			return 'pending';
		}
		return $answer->isCorrect ? 'correct' : 'incorrect';
	}
}
