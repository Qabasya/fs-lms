<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

readonly class DeleteParentEvent implements DeletionEventInterface {
	public function __construct( public int $parentPersonId, public int $actorId ) {}
}
