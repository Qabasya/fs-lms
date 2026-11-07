<?php
/**
 * Subject ranking read model.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Services\Profile\Learner\RankingPointsPolicy;

use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;

/** Subject-wide dense ranking, recomputed from task results in the current school trimester. */
class RankingCalculationService {

	/** Receives task-event storage and the independently configurable weight policy. */
	public function __construct(
		private readonly LearnerRankingRepository $repository,
		private readonly RankingPointsPolicy $points,
	) {}

	/** Shared subject-wide calculation used by learner tiles and the staff ranking table. */
	public function scoresForSubject( string $subject, string $nowLocal, ?array $period = null, bool $withBreakdown = false ): array {
		$period  = $period ?? $this->period( $nowLocal );
		$weights = $this->points->settings();
		$totals  = array_fill_keys( array_diff( $this->repository->studentIds( $subject ), $this->points->excludedStudents() ), 0.0 );
		$events  = array_merge(
			$this->repository->workEvents( $subject, $period['from'], $period['until'] ),
			$this->repository->assessmentEvents( $subject, $period['from'], $period['until'] )
		);
		$parts = array();
		foreach ( $events as $event ) {
			$id = (int) $event['student_person_id'];
			if ( array_key_exists( $id, $totals ) ) {
				$totals[ $id ] += (float) ( $weights[ (string) $event['source'] ] ?? 0 ) * (float) ( $event['task_points'] ?? 1.0 );
				$source = (string) $event['source'];
				$parts[ $id ][ $source ] = ( $parts[ $id ][ $source ] ?? 0 ) + (float) ( $event['task_points'] ?? 1.0 );
			}
		}
		$bonus = (float) $weights['attendance_bonus'];
		if ( $bonus > 0 ) {
			$streaks = array();
			$seen    = array();
			$length  = (int) $weights['attendance_streak_length'];
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
					$parts[ $id ]['attendance_bonus'] = ( $parts[ $id ]['attendance_bonus'] ?? 0 ) + 1;
					$streaks[ $id ] = 0;
				}
			}
		}
		$result = $this->rankTotals( $totals );
		if ( $withBreakdown ) {
			foreach ( $result as $id => &$row ) {
				$row['breakdown'] = array();
				$sources = array_unique( array_merge( array_diff( array_keys( $weights ), array( 'attendance_streak_length' ) ), array_keys( $parts[ $id ] ?? array() ) ) );
				foreach ( $sources as $source ) {
					$row['breakdown'][] = array( 'source' => $source, 'amount' => $parts[ $id ][ $source ] ?? 0, 'weight' => (float) ( $weights[ $source ] ?? 0 ), 'streak_length' => 'attendance_bonus' === $source ? (int) ( $weights['attendance_streak_length'] ?? 3 ) : null );
				}
			}
			unset( $row );
		}
		return $result;
	}

	public function visibleToLearners( string $subject ): bool {
		return $this->points->visibleToLearners( $subject );
	}

	/** Dense ranks from totals, shared by whole-subject and group views. */
	public function rankTotals( array $totals ): array {
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
		$now            = new \DateTimeImmutable( $nowLocal );
		$academic_start = ( (int) $now->format( 'n' ) >= 9 ) ? (int) $now->format( 'Y' ) : (int) $now->format( 'Y' ) - 1;
		$currentFrom    = $this->period( $nowLocal )['from'];
		$periods        = array();
		$next           = $academic_start + 1;
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
