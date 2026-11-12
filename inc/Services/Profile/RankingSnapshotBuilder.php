<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

/** Собирает независимые места направления и его групп из одного среза баллов. */
class RankingSnapshotBuilder {

	public function __construct( private readonly RankingCalculationService $ranking ) {}

	/**
	 * @param array<int, array{score: float|int, rank: int}> $scores
	 * @param array<int, array{student_person_id: int|string, group_id: int|string}> $roster
	 * @return array<int, array<int, int>> Ключ 0 — направление, остальные — ID групп.
	 */
	public function build( array $scores, array $roster ): array {
		$scopes = array( 0 => array() );
		$incomplete = array();
		foreach ( $scores as $row ) {
			if ( ! empty( $row['score_unknown'] ) ) {
				$incomplete[0] = true;
				break;
			}
		}
		foreach ( $roster as $record ) {
			$personId = (int) $record['student_person_id'];
			$groupId  = (int) $record['group_id'];
			if ( $personId <= 0 || $groupId <= 0 || ! isset( $scores[ $personId ] ) ) {
				continue;
			}
			$score = (float) $scores[ $personId ]['score'];
			$scopes[0][ $personId ] = $score;
			if ( ! empty( $scores[ $personId ]['score_unknown'] ) ) {
				$incomplete[0] = true;
			}
			if ( ( $record['access_mode'] ?? '' ) !== \Inc\Enums\Course\AccessMode::Individual->value ) {
				$scopes[ $groupId ][ $personId ] = $score;
				if ( ! empty( $scores[ $personId ]['score_unknown'] ) ) {
					$incomplete[ $groupId ] = true;
				}
			}
		}
		$result = array();
		foreach ( $scopes as $groupId => $totals ) {
			if ( ! empty( $incomplete[ $groupId ] ) ) {
				continue;
			}
			$result[ $groupId ] = array();
			foreach ( $this->ranking->rankTotals( $totals ) as $personId => $ranked ) {
				$result[ $groupId ][ $personId ] = $ranked['rank'];
			}
		}
		return $result;
	}
}
