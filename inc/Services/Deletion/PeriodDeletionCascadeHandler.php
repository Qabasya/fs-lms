<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

class PeriodDeletionCascadeHandler {

	public function __construct(
		private readonly GroupsRepository            $groups,
		private readonly AcademicPeriodRepository    $periods,
		private readonly DeletionEventDispatcher     $dispatcher,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	public function handle( DeletePeriodEvent $event ): void {
		$this->groups->withSelfPacedLifecycleLock( fn() => $this->handleLocked( $event ) );
	}

	private function handleLocked( DeletePeriodEvent $event ): void {
		$this->periods->refreshCache();
		$periodId   = $event->periodId;
		$actorId    = $event->actorId;
		$periodName = $this->periods->getById( $periodId )?->name;

		$dbGroups = $this->groups->findByPeriodId( $periodId );
		// Check the entire inventory before deleting even the first ordinary group.
		foreach ( $dbGroups as $group ) {
			if ( (int) ( $group->self_paced_course_id ?? 0 ) > 0 ) {
				throw new \DomainException( 'Удаление запрещено: есть самостоятельные курсы со служебными группами. История прохождения должна быть сохранена.' );
			}
		}
		foreach ( $dbGroups as $group ) {
			$this->dispatcher->dispatch( new DeleteGroupEvent( (int) $group->id, $actorId ) );
		}

		$this->periods->remove( $periodId );

		$this->logEvents->dispatch(
			LogEvent::PeriodDeleted,
			new EntityChangedEvent( $actorId, OperationType::Delete, EntityType::Period, $periodId, $periodName )
		);
	}
}
