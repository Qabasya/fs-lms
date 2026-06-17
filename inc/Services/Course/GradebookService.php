<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GradebookEntryDTO;
use Inc\Services\Course\AssessmentGradeSource;

class GradebookService {

	public function __construct(
		private readonly SubmissionGradeSource   $submissionSource,
		private readonly ?AssessmentGradeSource  $assessmentSource = null,
	) {}

	/** @return GradebookEntryDTO[] */
	public function forGroup( int $groupId ): array {
		$entries = $this->submissionSource->entriesForGroup( $groupId );
		if ( $this->assessmentSource ) {
			array_push( $entries, ...$this->assessmentSource->entriesForGroup( $groupId ) );
		}
		return $entries;
	}

	/** @return GradebookEntryDTO[] */
	public function forStudent( int $studentPersonId ): array {
		$entries = $this->submissionSource->entriesForStudent( $studentPersonId );
		if ( $this->assessmentSource ) {
			array_push( $entries, ...$this->assessmentSource->entriesForStudent( $studentPersonId ) );
		}
		return $entries;
	}
}
