<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

/** Сводит назначенные задания и их зачёты к одному результату на работу. */
class RankingAssignmentProjection {
	/** @param list<array<string,mixed>> $rows */
	public function build( array $rows ): array {
		$tasks = array();
		foreach ( $rows as $row ) {
			$id  = (int) $row['student_person_id'];
			$key = (int) $row['group_lesson_id'] . ':' . (int) $row['work_id'] . ':' . (int) $row['task_id'];
			if ( isset( $tasks[ $id ][ $key ] ) ) {
				$tasks[ $id ][ $key ]['credited'] = $tasks[ $id ][ $key ]['credited'] || ! empty( $row['credited'] );
			} else {
				$tasks[ $id ][ $key ] = $row;
			}
		}
		$result = array();
		foreach ( $tasks as $id => $assigned ) {
			$unknown = array();
			foreach ( $assigned as $task ) {
				$source                          = (string) $task['source'];
				$points                          = (float) $task['task_points'];
				$group                           = (string) $points;
				$result[ $id ][ $source ]      ??= array(
					'amount'      => 0.0,
					'max_amount'  => 0.0,
					'task_groups' => array(),
				);
				$part                            = &$result[ $id ][ $source ];
				if ( ! empty( $task['max_unknown'] ) ) {
					$unknown[ $source ] = true;
					$part['score_unknown'] = ! empty( $part['score_unknown'] ) || ! empty( $task['score_unknown'] );
					unset( $part );
					continue;
				}
				$part['max_amount']             += $points;
				$part['amount']                 += isset( $task['earned_points'] ) ? (float) $task['earned_points'] : ( empty( $task['credited'] ) ? 0 : $points );
				$part['task_groups'][ $group ] ??= array(
					'points'    => $points,
					'count'     => 0,
					'max_count' => 0,
				);
				++$part['task_groups'][ $group ]['max_count'];
				$part['task_groups'][ $group ]['count'] += empty( $task['credited'] ) ? 0 : 1;
				unset( $part );
			}
			foreach ( $result[ $id ] as &$part ) {
				ksort( $part['task_groups'], SORT_NUMERIC );
			}
			unset( $part );
			foreach ( array_keys( $unknown ) as $source ) {
				$result[ $id ][ $source ]['max_amount'] = null;
			}
		}
		return $result;
	}
}
