<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

class StudentOrphanCheckHandler {

	public function __construct(
		private readonly DeletionPredicates $predicates,
		private readonly DeletionEventDispatcher $dispatcher,
	) {}

	public function handle( StudentRecordsRemovedFromGroupEvent $event ): void {
		if ( $this->predicates->studentHasNoRemainingRecords( $event->studentPersonId ) ) {
			$this->dispatcher->dispatch(
				new DeleteStudentEvent( $event->studentPersonId, $event->actorId )
			);
		}
	}
}
