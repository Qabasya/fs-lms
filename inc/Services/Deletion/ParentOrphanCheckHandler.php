<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

class ParentOrphanCheckHandler {

	public function __construct(
		private readonly DeletionPredicates $predicates,
		private readonly DeletionEventDispatcher $dispatcher,
	) {}

	public function handle( ParentRecordsRemovedFromGroupEvent $event ): void {
		if ( $this->predicates->parentHasNoRemainingRecords( $event->parentPersonId ) ) {
			$this->dispatcher->dispatch(
				new DeleteParentEvent( $event->parentPersonId, $event->actorId )
			);
		}
	}
}
