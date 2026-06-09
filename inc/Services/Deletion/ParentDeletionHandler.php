<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Enums\AuditAction;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Shared\Traits\TransactionRunner;

class ParentDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly PersonRepository $persons,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly ConsentRepository $consents,
		private readonly ApplicationRepository $applications,
		private readonly UserManager $userManager,
		private readonly AuditService $audit,
	) {}

	public function handle( DeleteParentEvent $event ): void {
		$personId = $event->parentPersonId;
		$actorId  = $event->actorId;

		$person   = $this->persons->find( $personId );
		$wpUserId = $person?->wpUserId;

		$this->inTransaction( function () use ( $personId, $actorId ) {
			$this->consents->hardDeleteByPersonId( $personId );
			$this->applications->hardDeleteByParentPersonId( $personId );
			$this->personDocuments->hardDeleteByPersonId( $personId );
			$this->persons->hardDelete( $personId );

			$this->audit->record(
				AuditAction::HardDeletePerson->value,
				'parent',
				$personId,
				array( 'actor' => $actorId )
			);
		} );

		if ( $wpUserId ) {
			$this->userManager->delete( $wpUserId );
		}
	}
}
