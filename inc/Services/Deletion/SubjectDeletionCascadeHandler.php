<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Subject\SubjectDeletionService;
use Inc\Services\Subject\SubjectPagesService;

class SubjectDeletionCascadeHandler {

	public function __construct(
		private readonly GroupsRepository            $groups,
		private readonly SubjectDeletionService      $subjectDeletion,
		private readonly SubjectRepository           $subjects,
		private readonly RoomRepository              $rooms,
		private readonly DeletionEventDispatcher     $dispatcher,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly SubjectPagesService         $pages,
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
		// Страницы лендинга — в корзину, а не в снос: их содержимое (описание
		// предмета) писал редактор, и восстановить его должно быть чем.
		$this->pages->trashForSubject( $subjectKey );
		$this->rooms->removeSubjectFromAll( $subjectKey );
		$this->subjects->remove( $subjectKey );
		flush_rewrite_rules();

		$this->logEvents->dispatch(
			LogEvent::SubjectDeleted,
			new EntityChangedEvent( $actorId, OperationType::Delete, EntityType::Subject, $subjectKey, $subjectName )
		);
	}
}
