<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GradebookEntryDTO;

class GradebookService {

	public function __construct(
		private readonly GradeSourceRegistry $sources,
	) {}

	/** @return GradebookEntryDTO[] */
	public function forGroup( int $groupId ): array {
		$entries = array();
		foreach ( $this->sources->all() as $source ) {
			array_push( $entries, ...$source->entriesForGroup( $groupId ) );
		}
		return $entries;
	}

	/** @return GradebookEntryDTO[] */
	public function forStudent( int $studentPersonId ): array {
		$entries = array();
		foreach ( $this->sources->all() as $source ) {
			array_push( $entries, ...$source->entriesForStudent( $studentPersonId ) );
		}
		return $entries;
	}
}
