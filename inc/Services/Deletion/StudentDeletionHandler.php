<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\OperationType;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\TransactionRunner;

class StudentDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly PersonRepository          $persons,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly StudentRecordRepository   $studentRecords,
		private readonly ConsentRepository         $consents,
		private readonly ApplicationRepository     $applications,
		private readonly UserManager               $userManager,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function handle( DeleteStudentEvent $event ): void {
		$personId = $event->studentPersonId;
		$actorId  = $event->actorId;

		$person = $this->persons->find( $personId );
		$wpUserId = $person?->wpUserId;

		$recordsDeleted = $this->inTransaction( function () use ( $personId ) {
			$this->consents->hardDeleteByPersonId( $personId );
			$this->applications->hardDeleteByStudentPersonId( $personId );
			$this->personDocuments->hardDeleteByPersonId( $personId );
			$count = $this->studentRecords->deleteAllByStudent( $personId );
			$this->persons->hardDelete( $personId );
			return $count;
		} );

		$this->logEvents->dispatch(
			LogEvent::EntityHardDeleted,
			new EntityHardDeletedEvent( $actorId, 'person', $personId, 'consents, applications, person_documents, student_records:' . (int) $recordsDeleted )
		);

		if ( $wpUserId ) {
			$this->userManager->delete( $wpUserId );
		}

		$this->logEvents->dispatch(
			LogEvent::UserDeleted,
			new EntityChangedEvent( $actorId, OperationType::Delete, EntityType::Student, $personId )
		);
	}
}
