<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GradebookEntryDTO;

class GradebookService {

	public function __construct(
		private readonly SubmissionGradeSource $submissionSource,
	) {}

	/** @return GradebookEntryDTO[] */
	public function forGroup( int $groupId ): array {
		return $this->submissionSource->entriesForGroup( $groupId );
	}

	/** @return GradebookEntryDTO[] */
	public function forStudent( int $studentPersonId ): array {
		return $this->submissionSource->entriesForStudent( $studentPersonId );
	}
}
