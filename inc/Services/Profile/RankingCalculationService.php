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
		private readonly ?RankingAssignmentsService $assignments = null,
		private readonly bool $assignmentCalculationEnabled = true,
	) {}

	/** Shared subject-wide calculation used by learner tiles and the staff ranking table. */
	public function scoresForSubject( string $subject, string $nowLocal, ?array $period = null, bool $withBreakdown = false, ?array $policy = null ): array {
		$period  = $period ?? $this->period( $nowLocal );
		$policy = $policy ?? $this->points->forSubject( $subject );
		$weights = array_diff_key( $policy, array_flip( array( 'enabled', 'excluded_students', 'revision', 'generation' ) ) );
		foreach ( $weights as $key => $value ) {
			if ( 'attendance_streak_length' !== $key ) {
				$weights[ $key ] = round( (float) $value, 2 );
			}
		}
		$totals  = array_fill_keys( array_diff( $this->repository->studentIds( $subject ), $policy['excluded_students'] ), 0.0 );
		$events = array();
		$useAssignments = $this->assignmentCalculationEnabled && null !== $this->assignments;
		$assigned = $useAssignments ? ( new RankingAssignmentProjection() )->build( $this->assignments->tasks( $subject, $period['from'], $period['until'], $nowLocal ) ) : array();
		if ( ! $useAssignments ) {
			$events = array_merge( $this->repository->workEvents( $subject, $period['from'], $period['until'] ), $this->repository->assessmentEvents( $subject, $period['from'], $period['until'] ) );
		}
		$parts = array();
		$practiceGroups = array();
		$maxima = array();
		$unknownScores = array();
		foreach ( $assigned as $id => $sources ) {
			if ( ! array_key_exists( $id, $totals ) ) {
				continue;
			}
			foreach ( $sources as $source => $part ) {
				$parts[ $id ][ $source ] = $part['amount'];
				$maxima[ $id ][ $source ] = $part['max_amount'];
				$unknownScores[ $id ][ $source ] = ! empty( $part['score_unknown'] );
				$totals[ $id ] += $part['amount'] * (float) ( $weights[ $source ] ?? 0 );
				if ( 'practice' === $source ) {
					$practiceGroups[ $id ] = $part['task_groups'];
				}
			}
		}
		foreach ( $events as $event ) {
			$id = (int) $event['student_person_id'];
			if ( array_key_exists( $id, $totals ) ) {
				$totals[ $id ] += (float) ( $weights[ (string) $event['source'] ] ?? 0 ) * (float) ( $event['task_points'] ?? 1.0 );
				$source = (string) $event['source'];
				$parts[ $id ][ $source ] = ( $parts[ $id ][ $source ] ?? 0 ) + (float) ( $event['task_points'] ?? 1.0 );
				$maxima[ $id ][ $source ] = ( $maxima[ $id ][ $source ] ?? 0 ) + (float) ( $event['task_points'] ?? 1.0 );
				if ( $withBreakdown && 'practice' === $source ) {
					$points = (float) ( $event['task_points'] ?? 1.0 );
					$key = (string) $points;
					$practiceGroups[ $id ][ $key ] = ( $practiceGroups[ $id ][ $key ] ?? 0 ) + 1;
				}
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
				$maxima[ $id ]['attendance_bonus'] = floor( count( $seen[ $id ] ) / $length );
				$streaks[ $id ]           = empty( $event['present'] ) ? 0 : ( $streaks[ $id ] ?? 0 ) + 1;
				if ( $streaks[ $id ] >= $length ) {
					$totals[ $id ] += $bonus;
					$parts[ $id ]['attendance_bonus'] = ( $parts[ $id ]['attendance_bonus'] ?? 0 ) + 1;
					$streaks[ $id ] = 0;
				}
			}
		}
		$result = $this->rankTotals( $totals );
		$incomplete = false;
		foreach ( $result as $id => &$row ) {
			$row['score_unknown'] = false;
			foreach ( $unknownScores[ $id ] ?? array() as $source => $unknown ) {
				$row['score_unknown'] = $row['score_unknown'] || ( $unknown && (float) ( $weights[ $source ] ?? 0 ) > 0 );
			}
			$incomplete = $incomplete || $row['score_unknown'];
		}
		unset( $row );
		if ( $incomplete ) {
			foreach ( $result as &$row ) {
				$row['rank'] = null;
			}
			unset( $row );
		}
		if ( $withBreakdown ) {
			foreach ( $result as $id => &$row ) {
				$row['max_score'] = $useAssignments ? 0.0 : null;
				$row['max_unknown'] = false;
				$row['breakdown'] = array();
				$sources = array_unique( array_merge( array_diff( array_keys( $weights ), array( 'attendance_streak_length' ) ), array_keys( $parts[ $id ] ?? array() ) ) );
				foreach ( $sources as $source ) {
					$unknown = $useAssignments && array_key_exists( $source, $maxima[ $id ] ?? array() ) && null === $maxima[ $id ][ $source ];
					$scoreUnknown = ! empty( $unknownScores[ $id ][ $source ] ) && (float) ( $weights[ $source ] ?? 0 ) > 0;
					$row['score_unknown'] = $row['score_unknown'] || $scoreUnknown;
					$maxAmount = $useAssignments && ! $unknown ? (float) ( $maxima[ $id ][ $source ] ?? 0 ) : null;
					if ( null !== $maxAmount ) {
						if ( null !== $row['max_score'] ) {
							$row['max_score'] += $maxAmount * (float) ( $weights[ $source ] ?? 0 );
						}
					} elseif ( $unknown && (float) ( $weights[ $source ] ?? 0 ) > 0 ) {
						$row['max_score'] = null;
						$row['max_unknown'] = true;
					}
					$row['breakdown'][] = array( 'source' => $source, 'amount' => $parts[ $id ][ $source ] ?? 0, 'max_amount' => $maxAmount, 'max_unknown' => $unknown, 'score_unknown' => $scoreUnknown, 'weight' => (float) ( $weights[ $source ] ?? 0 ), 'streak_length' => 'attendance_bonus' === $source ? (int) ( $weights['attendance_streak_length'] ?? 3 ) : null );
					if ( 'practice' === $source ) {
						$groups = $practiceGroups[ $id ] ?? array();
						ksort( $groups, SORT_NUMERIC );
						$last = array_key_last( $row['breakdown'] );
						$row['breakdown'][ $last ]['task_groups'] = array();
						foreach ( $groups as $points => $group ) {
							$row['breakdown'][ $last ]['task_groups'][] = is_array( $group ) ? $group : array( 'points' => (float) $points, 'count' => $group );
						}
					}
				}
			}
			unset( $row );
		}
		return $result;
	}

	/** The same policy must drive scores and the snapshot fingerprint. */
	public function subjectPolicy( string $subject ): array {
		return $this->points->forSubject( $subject );
	}

	public function usesSubjectSettings(): bool {
		return $this->points->usesSubjectSettings();
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
