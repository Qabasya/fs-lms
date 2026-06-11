<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\AuditAction;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\OperationType;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\AuditService;
use Inc\Services\Subject\SubjectDeletionService;

class SubjectDeletionCascadeHandler {

	public function __construct(
		private readonly GroupsRepository            $groups,
		private readonly SubjectDeletionService      $subjectDeletion,
		private readonly SubjectRepository           $subjects,
		private readonly DeletionEventDispatcher     $dispatcher,
		private readonly AuditService                $audit,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function handle( DeleteSubjectEvent $event ): void {
		$subjectKey  = $event->subjectKey;
		$actorId     = $event->actorId;
		$subjectName = $this->subjects->getByKey( $subjectKey )?->name;

		$dbGroups = $this->groups->findBySubjectKey( $subjectKey );
		foreach ( $dbGroups as $group ) {
			$this->dispatcher->dispatch( new DeleteGroupEvent( (int) $group->id, $actorId ) );
		}

		$this->subjectDeletion->deleteWithCascade( $subjectKey );
		$this->subjects->remove( $subjectKey );
		flush_rewrite_rules();

		$this->audit->record(
			AuditAction::HardDeleteSubject->value,
			'subject',
			null,
			array( 'subject_key' => $subjectKey, 'actor' => $actorId )
		);

		$this->logEvents->dispatch(
			LogEvent::SubjectDeleted,
			new EntityChangedEvent( $actorId, OperationType::Delete, EntityType::Subject, null, $subjectName )
		);
	}
}
