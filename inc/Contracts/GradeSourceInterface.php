<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Course\GradebookEntryDTO;

interface GradeSourceInterface {

	/** @return GradebookEntryDTO[] */
	public function entriesForGroup( int $groupId ): array;

	/** @return GradebookEntryDTO[] */
	public function entriesForStudent( int $studentPersonId ): array;
}
