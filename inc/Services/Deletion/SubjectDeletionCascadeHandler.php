<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Enums\AuditAction;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\AuditService;
use Inc\Services\Subject\SubjectDeletionService;

class SubjectDeletionCascadeHandler {

	public function __construct(
		private readonly GroupsRepository $groups,
		private readonly SubjectDeletionService $subjectDeletion,
		private readonly SubjectRepository $subjects,
		private readonly DeletionEventDispatcher $dispatcher,
		private readonly AuditService $audit,
	) {}

	public function handle( DeleteSubjectEvent $event ): void {
		$subjectKey = $event->subjectKey;
		$actorId    = $event->actorId;

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
	}
}
