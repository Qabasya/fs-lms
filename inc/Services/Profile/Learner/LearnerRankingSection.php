<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\Services\Profile\RankingCalculationService;

/** Learner tile projection of the shared ranking calculation. */
class LearnerRankingSection {
	public function __construct( private readonly RankingCalculationService $ranking ) {}

	/**
	 * Рейтинг считается во всех четырёх периодах учебного года, включая летний (июнь — август).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forGroups( int $personId, array $groups, string $nowLocal ): array {
		$period = $this->ranking->period( $nowLocal );

		$items = array();
		foreach ( $groups as $group ) {
			$key = (string) ( $group['subject_key'] ?? '' );
			if ( '' === $key || isset( $items[ $key ] ) || ! $this->ranking->visibleToLearners( $key ) ) {
				continue;
			}
			$scores = $this->ranking->scoresForSubject( $key, $nowLocal );
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


	public function scoresForSubject( string $subject, string $nowLocal, ?array $period = null ): array {
		return $this->ranking->scoresForSubject( $subject, $nowLocal, $period );
	}
	public function period( string $nowLocal ): array {
		return $this->ranking->period( $nowLocal );
	}
	public function periodsFor( string $nowLocal ): array {
		return $this->ranking->periodsFor( $nowLocal );
	}
}
