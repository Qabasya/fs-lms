<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

readonly class DeleteSubjectEvent implements DeletionEventInterface {
	public function __construct( public string $subjectKey, public int $actorId ) {}
}
