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
use Inc\Enums\Settings\AvatarEntity;
use Inc\Services\Shared\AvatarService;
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
		private readonly AvatarService               $avatars,
	) {}

	public function handle( DeleteSubjectEvent $event ): void {
		$this->groups->withSelfPacedLifecycleLock( fn() => $this->handleLocked( $event ) );
	}

	private function handleLocked( DeleteSubjectEvent $event ): void {
		$this->subjects->refreshCache();
		$subjectKey  = $event->subjectKey;
		$actorId     = $event->actorId;
		$subjectName = $this->subjects->getByKey( $subjectKey )?->name;

		$dbGroups = $this->groups->findBySubjectKey( $subjectKey );
		// Check the entire inventory before deleting even the first ordinary group.
		foreach ( $dbGroups as $group ) {
			if ( (int) ( $group->self_paced_course_id ?? 0 ) > 0 ) {
				throw new \DomainException( 'Удаление запрещено: есть самостоятельные курсы со служебными группами. История прохождения должна быть сохранена.' );
			}
		}
		foreach ( $dbGroups as $group ) {
			$this->dispatcher->dispatch( new DeleteGroupEvent( (int) $group->id, $actorId ) );
		}

		$this->subjectDeletion->deleteWithCascade( $subjectKey );
		// Страницы лендинга — в корзину, а не в снос: их содержимое (описание
		// предмета) писал редактор, и восстановить его должно быть чем.
		$this->pages->trashForSubject( $subjectKey );
		$this->rooms->removeSubjectFromAll( $subjectKey );
		// Иначе новый предмет с тем же ключом унаследует старую картинку.
		$this->avatars->set( AvatarEntity::Subject, $subjectKey, 0 );
		$this->subjects->remove( $subjectKey );
		flush_rewrite_rules();

		$this->logEvents->dispatch(
			LogEvent::SubjectDeleted,
			new EntityChangedEvent( $actorId, OperationType::Delete, EntityType::Subject, $subjectKey, $subjectName )
		);
	}
}
