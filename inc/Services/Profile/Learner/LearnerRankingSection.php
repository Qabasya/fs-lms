<?php
/**
 * Subject ranking read model.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;

/** Subject-wide dense ranking, recomputed from task results in the current school trimester. */
class LearnerRankingSection {

	/** Receives task-event storage and the independently configurable weight policy. */
	public function __construct(
		private readonly LearnerRankingRepository $repository,
		private readonly RankingPointsPolicy $points,
	) {}

	/**
	 * Summer has no ranking. New subjects need no special configuration.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forGroups( int $personId, array $groups, string $nowLocal ): array {
		$period = $this->period( $nowLocal );
		if ( null === $period ) {
			return array();
		}

		$items = array();
		foreach ( $groups as $group ) {
			$key = (string) ( $group['subject_key'] ?? '' );
			if ( '' === $key || isset( $items[ $key ] ) ) {
				continue;
			}
			$scores = $this->scoresForSubject( $key, $nowLocal );
			if ( ! isset( $scores[ $personId ] ) ) {
				continue;
			}
			$items[ $key ] = array(
				'subject_key'  => $key,
				'subject'      => (string) ( $group['subject'] ?? $key ),
				'score'        => $scores[ $personId ]['score'],
				'rank'         => $scores[ $personId ]['rank'],
				'period'       => $period['label'],
				'period_start' => $period['from'],
				'period_end'   => $period['until'],
			);
		}

		return array_values( $items );
	}

	/** Shared subject-wide calculation used by learner tiles and the staff ranking table. */
	public function scoresForSubject( string $subject, string $nowLocal ): array {
		$period = $this->period( $nowLocal );
		if ( null === $period ) {
			return array();
		}
		$totals = array_fill_keys( $this->repository->studentIds( $subject ), 0.0 );
		$events = array_merge(
			$this->repository->workEvents( $subject, $period['from'], $period['until'] ),
			$this->repository->assessmentEvents( $subject, $period['from'], $period['until'] )
		);
		foreach ( $events as $event ) {
			$id = (int) $event['student_person_id'];
			if ( array_key_exists( $id, $totals ) ) {
				$totals[ $id ] += $this->points->forSolvedTask( (string) $event['source'] ) * (float) ( $event['task_points'] ?? 1.0 );
			}
		}
		$bonus = $this->points->attendanceBonus();
		if ( $bonus > 0 ) {
			$streaks = array();
			$seen    = array();
			$length  = $this->points->attendanceStreakLength();
			foreach ( $this->repository->attendanceEvents( $subject, $period['from'], $period['until'], $nowLocal ) as $event ) {
				$id       = (int) $event['student_person_id'];
				$lessonId = (int) $event['group_lesson_id'];
				if ( ! array_key_exists( $id, $totals ) || isset( $seen[ $id ][ $lessonId ] ) ) {
					continue;
				}
				$seen[ $id ][ $lessonId ] = true;
				$streaks[ $id ]           = empty( $event['present'] ) ? 0 : ( $streaks[ $id ] ?? 0 ) + 1;
				if ( $streaks[ $id ] >= $length ) {
					$totals[ $id ] += $bonus;
					$streaks[ $id ] = 0;
				}
			}
		}
		// Equal decimal totals must share a rank regardless of addition order.
		$totals = array_map( static fn( float $score ): float => round( $score, 2 ), $totals );

		arsort( $totals, SORT_NUMERIC );
		$result   = array();
		$rank     = 0;
		$previous = null;
		foreach ( $totals as $id => $score ) {
			if ( null === $previous || $score !== $previous ) {
				++$rank;
			}
			$result[ $id ] = array(
				'score' => $score,
				'rank'  => $rank,
			);
			$previous      = $score;
		}
		return $result;
	}

	/**
	 * Current school trimester, with an exclusive upper date bound.
	 *
	 * @return array{from: string, until: string, label: string}|null
	 */
	public function period( string $nowLocal ): ?array {
		$now   = new \DateTimeImmutable( $nowLocal );
		$month = (int) $now->format( 'n' );
		$year  = (int) $now->format( 'Y' );
		if ( $month >= 6 && $month <= 8 ) {
			return null;
		}
		if ( $month >= 9 && $month <= 11 ) {
			return array(
				'from'  => "$year-09-01 00:00:00",
				'until' => "$year-12-01 00:00:00",
				'label' => "Сентябрь — ноябрь $year",
			);
		}
		if ( $month >= 3 && $month <= 5 ) {
			return array(
				'from'  => "$year-03-01 00:00:00",
				'until' => "$year-06-01 00:00:00",
				'label' => "Март — май $year",
			);
		}
		$start_year = 12 === $month ? $year : $year - 1;
		$end_year   = $start_year + 1;
		return array(
			'from'  => "$start_year-12-01 00:00:00",
			'until' => "$end_year-03-01 00:00:00",
			'label' => "Декабрь $start_year — февраль $end_year",
		);
	}
}
