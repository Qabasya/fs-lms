<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

readonly class DeleteGroupEvent implements DeletionEventInterface {
	public function __construct( public int $groupId, public int $actorId ) {}
}
