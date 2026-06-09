<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

readonly class DeletionPredicates {

	public function __construct(
		private StudentRecordRepository $studentRecords,
	) {}

	public function studentHasNoRemainingRecords( int $studentPersonId ): bool {
		return ! $this->studentRecords->hasAnyRecord( $studentPersonId );
	}

	public function parentHasNoRemainingRecords( int $parentPersonId ): bool {
		return ! $this->studentRecords->hasAnyRecordForParent( $parentPersonId );
	}
}
