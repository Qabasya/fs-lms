<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Assessment\AttemptStatus;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Assessment\AttemptOutcomeService;
use Inc\Services\Assessment\SecondaryScoreService;

/**
 * Class OwnWorkDetailService
 *
 * Деталь СВОЕЙ работы/попытки для ученика («Мои оценки», задачи 12/13). Обёртка
 * над {@see WorkDetailService::forWork()} с проверкой владения ($personId) и
 * футером результата (номер попытки, время, первичный/вторичный балл).
 *
 * ЕГЭ (п.13 исходных замечаний): эталонные ответы отдаются ТОЛЬКО после завершения
 * попытки (Submitted/Graded) — до этого поле `correct` вырезается. Правильные
 * ответы видны исключительно здесь, в «Мои оценки».
 *
 * @package Inc\Services\Course
 */
class OwnWorkDetailService {

	public function __construct(
		private readonly WorkDetailService            $detail,
		private readonly AssessmentAttemptRepository  $attempts,
		private readonly SubmissionRepository         $submissions,
		private readonly AssessmentManager            $assessments,
		private readonly AttemptOutcomeService        $outcome,
		private readonly SecondaryScoreService        $secondaryScore,
	) {}

	/**
	 * @return array<string,mixed>|null null — не найдено / не принадлежит ученику.
	 */
	public function forOwner( string $sourceType, int $sourceId, int $personId ): ?array {
		return match ( $sourceType ) {
			'attempt'    => $this->fromAttempt( $sourceId, $personId ),
			'submission' => $this->fromSubmission( $sourceId, $personId ),
			default      => null,
		};
	}

	private function fromAttempt( int $attemptId, int $personId ): ?array {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt || $attempt->studentPersonId !== $personId ) {
			return null;
		}

		$detail = $this->detail->forWork( 'attempt', $attemptId );
		if ( null === $detail ) {
			return null;
		}
		unset( $detail['group_id'] );

		// Эталонные ответы — только после завершения попытки (ЕГЭ, п.13).
		$completed = in_array( $attempt->status, array( AttemptStatus::Submitted, AttemptStatus::Graded ), true );
		if ( ! $completed && ! empty( $detail['tasks'] ) ) {
			foreach ( $detail['tasks'] as &$t ) {
				$t['correct'] = null;
			}
			unset( $t );
		}

		$assessment     = $this->assessments->get( $attempt->assessmentId );
		$secondary      = null;
		if ( null !== $assessment && $assessment->kind->needsSecondaryScore() ) {
			$secondary = $this->secondaryScore->translate( $attempt->totalScore ?? 0.0, $assessment->scoreMap );
		}

		$detail['read_only'] = true;
		$detail['footer']    = array(
			'attempt_number'   => $attempt->attemptNumber,
			'duration_seconds' => $attempt->actualDurationSeconds(),
			'primary_score'    => $attempt->totalScore,
			'max_score'        => $attempt->maxScore,
			'secondary_score'  => $secondary,
			'outcome'          => null !== $assessment ? $this->outcome->label( $attempt, $assessment ) : '',
			'outcome_state'    => null !== $assessment ? $this->outcome->state( $attempt, $assessment ) : 'fail',
		);

		return $detail;
	}

	private function fromSubmission( int $submissionId, int $personId ): ?array {
		$sub = $this->submissions->find( $submissionId );
		if ( null === $sub || $sub->studentPersonId !== $personId ) {
			return null;
		}

		$detail = $this->detail->forWork( 'submission', $submissionId );
		if ( null === $detail ) {
			return null;
		}
		unset( $detail['group_id'] );

		$detail['read_only'] = true;
		$detail['footer']    = array(
			'attempt_number'   => null,
			'duration_seconds' => null,
			'submitted_at'     => $sub->submittedAt ?? null,
			'primary_score'    => $sub->score,
			'max_score'        => $sub->maxScore,
			'secondary_score'  => null,
			'outcome'          => '',
			'outcome_state'    => '',
		);

		return $detail;
	}
}
