<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\TransactionRunner;

class GroupDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly GroupsRepository $groups,
		private readonly DeletionEventDispatcher    $dispatcher,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function handle( DeleteGroupEvent $event ): void {
		$groupId = $event->groupId;
		$actorId = $event->actorId;

		$affected = $this->inTransaction( function () use ( $groupId ) {
			$ids = $this->studentRecords->deleteAllByGroupAndCollect( $groupId );
			$this->groups->hardDelete( $groupId );
			return $ids;
		} );

		$studentsCount = count( $affected['students'] ?? array() );
		$parentsCount  = count( $affected['parents'] ?? array() );
		$this->logEvents->dispatch(
			LogEvent::EntityHardDeleted,
			new EntityHardDeletedEvent( $actorId, 'group', $groupId, "students:{$studentsCount}, parents:{$parentsCount}" )
		);

		foreach ( $affected['students'] as $studentPersonId ) {
			$this->dispatcher->dispatch(
				new StudentRecordsRemovedFromGroupEvent( $studentPersonId, $actorId )
			);
		}

		foreach ( $affected['parents'] as $parentPersonId ) {
			$this->dispatcher->dispatch(
				new ParentRecordsRemovedFromGroupEvent( $parentPersonId, $actorId )
			);
		}
	}
}
