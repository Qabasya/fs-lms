<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Shared\Traits\TransactionRunner;

class ParentDeletionHandler {

	use TransactionRunner;

	public function __construct(
		private readonly PersonRepository          $persons,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly ConsentRepository         $consents,
		private readonly ApplicationRepository     $applications,
		private readonly UserManager               $userManager,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function handle( DeleteParentEvent $event ): void {
		$personId = $event->parentPersonId;
		$actorId  = $event->actorId;

		$person   = $this->persons->find( $personId );
		$wpUserId = $person?->wpUserId;

		// Защита от кросс-ролевого удаления: под видом «родителя-сироты» мог прийти
		// ученик (битая привязка parent_person_id → person с is_student=1). Удалять
		// ученика через родительский каскад нельзя — иначе теряем реального ученика.
		if ( null !== $person && $person->isStudent ) {
			\Inc\Shared\PluginLogger::warning(
				'ParentDeletion',
				"Пропущено удаление person #{$personId}: это ученик, а не родитель (битая привязка parent_person_id)",
				array( 'person_id' => $personId )
			);
			return;
		}

		$this->inTransaction( function () use ( $personId ) {
			$this->consents->hardDeleteByPersonId( $personId );
			$this->applications->hardDeleteByParentPersonId( $personId );
			$this->personDocuments->hardDeleteByPersonId( $personId );
			$this->persons->hardDelete( $personId );
		} );

		$this->logEvents->dispatch(
			LogEvent::EntityHardDeleted,
			new EntityHardDeletedEvent( $actorId, 'person', $personId, 'consents, applications, person_documents' )
		);

		if ( $wpUserId ) {
			$this->userManager->delete( $wpUserId );
		}

		$this->logEvents->dispatch(
			LogEvent::UserDeleted,
			new EntityChangedEvent( $actorId, OperationType::Delete, EntityType::Parent, $personId )
		);
	}
}
