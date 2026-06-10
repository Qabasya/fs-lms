<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

readonly class DeleteStudentEvent implements DeletionEventInterface {
	public function __construct( public int $studentPersonId, public int $actorId ) {}
}
