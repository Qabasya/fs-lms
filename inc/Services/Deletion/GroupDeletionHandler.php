<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Log\DeletionLogWriter;
use Inc\Shared\Traits\TransactionRunner;

class GroupDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly GroupsRepository $groups,
		private readonly DeletionEventDispatcher $dispatcher,
		private readonly DeletionLogWriter       $deletionLog,
	) {}

	public function handle( DeleteGroupEvent $event ): void {
		$groupId = $event->groupId;
		$actorId = $event->actorId;

		$affected = $this->inTransaction( function () use ( $groupId ) {
			$ids = $this->studentRecords->deleteAllByGroupAndCollect( $groupId );
			$this->groups->hardDelete( $groupId );

			$studentsCount = count( $ids['students'] ?? array() );
			$parentsCount  = count( $ids['parents'] ?? array() );
			$this->deletionLog->record(
				'group',
				$groupId,
				"students:{$studentsCount}, parents:{$parentsCount}"
			);

			return $ids;
		} );

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
