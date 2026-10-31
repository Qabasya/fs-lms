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
	 * Рейтинг считается во всех четырёх периодах учебного года, включая летний (июнь — август).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forGroups( int $personId, array $groups, string $nowLocal ): array {
		$period = $this->period( $nowLocal );

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
	public function scoresForSubject( string $subject, string $nowLocal, ?array $period = null ): array {
		$period = $period ?? $this->period( $nowLocal );
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
	 * Текущий период учебного года, с эксклюзивной верхней границей.
	 *
	 * Год делится на четыре фиксированных периода: сентябрь — ноябрь, декабрь — февраль,
	 * март — май, июнь — август (рейтинг считается и летом).
	 *
	 * @return array{from: string, until: string, label: string}
	 */
	public function period( string $nowLocal ): array {
		$now   = new \DateTimeImmutable( $nowLocal );
		$month = (int) $now->format( 'n' );
		$year  = (int) $now->format( 'Y' );
		if ( $month >= 6 && $month <= 8 ) {
			return array(
				'from'  => "$year-06-01 00:00:00",
				'until' => "$year-09-01 00:00:00",
				'label' => "Июнь — август $year",
			);
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

	/**
	 * Периоды для выбора на экране «Рейтинг»: только уже начавшиеся периоды текущего
	 * учебного года (с сентября) — текущий и прошлые, будущие не предлагаются.
	 * Новые — первыми; у текущего периода `current: true`.
	 *
	 * @return list<array{from: string, until: string, label: string, current: bool}>
	 */
	public function periodsFor( string $nowLocal ): array {
		$now           = new \DateTimeImmutable( $nowLocal );
		$academic_start = ( (int) $now->format( 'n' ) >= 9 ) ? (int) $now->format( 'Y' ) : (int) $now->format( 'Y' ) - 1;
		$currentFrom   = $this->period( $nowLocal )['from'];
		$periods       = array();
		$next          = $academic_start + 1;
		foreach ( array(
			array( "$academic_start-09-01 00:00:00", "$academic_start-12-01 00:00:00", "Сентябрь — ноябрь $academic_start" ),
			array( "$academic_start-12-01 00:00:00", "$next-03-01 00:00:00", "Декабрь $academic_start — февраль $next" ),
			array( "$next-03-01 00:00:00", "$next-06-01 00:00:00", "Март — май $next" ),
			array( "$next-06-01 00:00:00", "$next-09-01 00:00:00", "Июнь — август $next" ),
		) as $row ) {
			// Будущие периоды не предлагаем.
			if ( $row[0] > $nowLocal ) {
				continue;
			}
			$periods[] = array(
				'from'    => $row[0],
				'until'   => $row[1],
				'label'   => $row[2],
				'current' => $row[0] === $currentFrom,
			);
		}

		return array_reverse( $periods );
	}
}
