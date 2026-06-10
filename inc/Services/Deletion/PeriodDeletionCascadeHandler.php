<?php

declare( strict_types=1 );

namespace Inc\Services\Deletion;

use Inc\Enums\AuditAction;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\AuditService;

class PeriodDeletionCascadeHandler {

	public function __construct(
		private readonly GroupsRepository $groups,
		private readonly AcademicPeriodRepository $periods,
		private readonly DeletionEventDispatcher $dispatcher,
		private readonly AuditService $audit,
	) {}

	public function handle( DeletePeriodEvent $event ): void {
		$periodId = $event->periodId;
		$actorId  = $event->actorId;

		$dbGroups = $this->groups->findByPeriodId( $periodId );
		foreach ( $dbGroups as $group ) {
			$this->dispatcher->dispatch( new DeleteGroupEvent( (int) $group->id, $actorId ) );
		}

		$this->periods->remove( $periodId );

		$this->audit->record(
			AuditAction::HardDeletePeriod->value,
			'academic_period',
			null,
			array( 'period_id' => $periodId, 'actor' => $actorId )
		);
	}
}
