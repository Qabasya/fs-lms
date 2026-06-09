<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Enums\AuditAction;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\AuditService;
use Inc\Shared\Traits\TransactionRunner;

class StudentDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly PersonRepository $persons,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly StudentRecordRepository $studentRecords,
		private readonly ConsentRepository $consents,
		private readonly ApplicationRepository $applications,
		private readonly UserManager $userManager,
		private readonly AuditService $audit,
	) {}

	public function handle( DeleteStudentEvent $event ): void {
		$personId = $event->studentPersonId;
		$actorId  = $event->actorId;

		$person = $this->persons->find( $personId );
		$wpUserId = $person?->wpUserId;

		$this->inTransaction( function () use ( $personId, $actorId ) {
			$this->consents->hardDeleteByPersonId( $personId );
			$this->applications->hardDeleteByStudentPersonId( $personId );
			$this->personDocuments->hardDeleteByPersonId( $personId );
			$this->studentRecords->deleteAllByStudent( $personId );
			$this->persons->hardDelete( $personId );

			$this->audit->record(
				AuditAction::HardDeletePerson->value,
				'student',
				$personId,
				array( 'actor' => $actorId )
			);
		} );

		if ( $wpUserId ) {
			$this->userManager->delete( $wpUserId );
		}
	}
}
