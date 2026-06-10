<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

readonly class DeletePeriodEvent implements DeletionEventInterface {
	public function __construct( public string $periodId, public int $actorId ) {}
}
