<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Log\DeletionLogWriter;
use Inc\Shared\Traits\TransactionRunner;

class ParentDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly PersonRepository $persons,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly ConsentRepository $consents,
		private readonly ApplicationRepository $applications,
		private readonly UserManager       $userManager,
		private readonly DeletionLogWriter $deletionLog,
	) {}

	public function handle( DeleteParentEvent $event ): void {
		$personId = $event->parentPersonId;
		$actorId  = $event->actorId;

		$person   = $this->persons->find( $personId );
		$wpUserId = $person?->wpUserId;

		$this->inTransaction( function () use ( $personId ) {
			$this->consents->hardDeleteByPersonId( $personId );
			$this->applications->hardDeleteByParentPersonId( $personId );
			$this->personDocuments->hardDeleteByPersonId( $personId );
			$this->persons->hardDelete( $personId );

			$this->deletionLog->record( 'person', $personId, 'consents, applications, person_documents' );
		} );

		if ( $wpUserId ) {
			$this->userManager->delete( $wpUserId );
		}
	}
}
