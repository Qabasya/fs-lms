<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\OperationType;
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
		$periodId   = $event->periodId;
		$actorId    = $event->actorId;
		$periodName = $this->periods->getById( $periodId )?->name;

		$dbGroups = $this->groups->findByPeriodId( $periodId );
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
