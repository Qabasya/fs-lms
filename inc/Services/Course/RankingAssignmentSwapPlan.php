<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Course\RankingAssignmentOrigin;

/** Перемещает историю темы и оставляет историю дополнительных работ на занятии. */
class RankingAssignmentSwapPlan {
	/** @return array{array, array} */
	public function build( array $first, array $second ): array {
		$firstParts = $this->split( $first );
		$secondParts = $this->split( $second );
		return array(
			$this->combine( $firstParts['extra'], $secondParts['lesson'] ),
			$this->combine( $secondParts['extra'], $firstParts['lesson'] ),
		);
	}

	private function split( array $snapshots ): array {
		$parts = array( 'lesson' => array(), 'extra' => array() );
		foreach ( $snapshots as $id => $snapshot ) {
			$origin = RankingAssignmentOrigin::tryFrom( (string) ( $snapshot['origin'] ?? '' ) );
			if ( ! $origin ) {
				throw new \DomainException( 'Неизвестно происхождение работы; перенос истории невозможен.' );
			}
			$parts[ $origin->value ][ $id ] = $snapshot;
		}
		return $parts;
	}

	private function combine( array $extra, array $lesson ): array {
		if ( array_intersect_key( $extra, $lesson ) ) {
			throw new \DomainException( 'Работа присутствует и в теме, и в дополнительных работах; перенос истории невозможен.' );
		}
		return $extra + $lesson;
	}
}
