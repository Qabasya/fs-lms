<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Services\Assessment\SecondaryScoreService;

/**
 * Вычисление баллов КЕГЭ и ОГЭ (7.3).
 *
 * @package Inc\Services\Exam
 */
class ExamScoreService {

	public function __construct(
		private readonly ExamFormatRegistry $formatRegistry,
		private readonly ExamEventRepository $eventRepo,
		private readonly AssessmentManager $assessments,
		private readonly ScoringUnits $scoringUnits,
		private readonly SecondaryScoreService $secondaryScores,
	) {}

	/**
	 * Итоговые баллы попытки (7.3.1).
	 *
	 * @param AttemptDTO $attempt Попытка
	 * @param ExamEventDTO $event Проведение
	 *
	 * @return array{
	 *     direction: 'ege'|'oge',
	 *     primary: int,
	 *     primary_max: int,
	 *     secondary: ?int,
	 *     secondary_max: ?int,
	 *     grade: ?int,
	 *     grade_max: ?int,
	 *     pending: bool,
	 *     final: bool
	 * }
	 */
	public function summarize( AttemptDTO $attempt, ExamEventDTO $event ): array {
		$snapshot = $this->eventRepo->snapshotFor( $event->id );
		$primaryMax = $snapshot['primary_max'] ?? $this->getPrimaryMax( $event );

		// Первичный балл (округленный total_score)
		$primary = (int) round( $attempt->totalScore ?? 0 );

		// Есть ли ещё задания на ручной проверке
		$assessment = $this->assessments->get( $attempt->assessmentId );
		$pending = $assessment && $assessment->hasManual;

		// Вторичный балл и отметка
		$secondary = null;
		$secondaryMax = null;
		$grade = null;
		$gradeMax = null;

		if ( 'oge' === $event->direction ) {
			$gradeMax = 5;
			// ОГЭ: отметка из первичных баллов
			if ( ! $pending && isset( $snapshot['grade_scale'] ) ) {
				$grade = $this->translateOgeGrade( $primary, $snapshot['grade_scale'] );
			}
		} else {
			// ЕГЭ: вторичный балл из шкалы
			$secondaryMax = 100;
			if ( isset( $snapshot['scale'] ) ) {
				$secondary = $this->secondaryScores->translate( $primary, $snapshot['scale'] );
			}
		}

		return array(
			'direction'     => $event->direction,
			'primary'       => $primary,
			'primary_max'   => $primaryMax,
			'secondary'     => $secondary,
			'secondary_max' => $secondaryMax,
			'grade'         => $grade,
			'grade_max'     => $gradeMax,
			'pending'       => $pending,
			'final'         => ! $pending,
		);
	}

	/**
	 * Баллы по единицам (7.3.3).
	 *
	 * @param AttemptDTO $attempt Попытка
	 * @param array $tasks Задачи из WorkDetailService
	 *
	 * @return array[] Массив с unit_key, number, score, max, status
	 */
	public function units( AttemptDTO $attempt, array $tasks ): array {
		$byUnit = array();

		foreach ( $tasks as $task ) {
			$key = $task['unit_key'] ?? null;
			if ( ! $key ) {
				continue;
			}

			if ( ! isset( $byUnit[ $key ] ) ) {
				$byUnit[ $key ] = array(
					'unit_key' => $key,
					'number'   => $task['number'] ?? '?',
					'score'    => 0,
					'max'      => 0,
					'status'   => 'pending',
				);
			}

			// Аккумулировать баллы
			$byUnit[ $key ]['score'] += (int) ( $task['score'] ?? 0 );
			$byUnit[ $key ]['max']   += (int) ( $task['max_score'] ?? 0 );

			// Худший статус (pending > unanswered/incorrect > partial > correct)
			$statuses = array( 'pending' => 4, 'unanswered' => 3, 'incorrect' => 3, 'partial' => 2, 'correct' => 1 );
			$taskStatus = $task['verdict'] ?? 'pending';
			$taskLevel = $statuses[ $taskStatus ] ?? 0;
			$currentLevel = $statuses[ $byUnit[ $key ]['status'] ] ?? 0;
			if ( $taskLevel > $currentLevel ) {
				$byUnit[ $key ]['status'] = $taskStatus;
			}
		}

		return array_values( $byUnit );
	}

	/**
	 * Максимум первичных баллов для направления.
	 */
	private function getPrimaryMax( ExamEventDTO $event ): int {
		$format = $this->formatRegistry->for( $event->subject_key, $event->direction );
		return $format ? $format->primaryMax : 21; // ОГЭ по умолчанию
	}

	/**
	 * Перевод первичных баллов в отметку ОГЭ.
	 */
	private function translateOgeGrade( int $primary, array $scale ): ?int {
		foreach ( $scale as $threshold => $grade ) {
			if ( $primary >= $threshold ) {
				return (int) $grade;
			}
		}
		return null;
	}
}
