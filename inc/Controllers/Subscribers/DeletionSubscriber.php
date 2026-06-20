<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Services\Log\EntityAuditLogWriter;

class DeletionSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly EntityAuditLogWriter        $writer,
	) {}

	public function register(): void {
		$handler = array( $this, 'handle' );
		$this->logEvents->subscribe( LogEvent::EntityHardDeleted, $handler );
		$this->logEvents->subscribe( LogEvent::PersonSoftDeleted, $handler );
	}

	public function handle( EntityHardDeletedEvent $event ): void {
		$entityType = EntityType::tryFrom( $event->entityType );
		if ( $entityType === null ) {
			return;
		}
		$this->writer->record(
			$event->actorUserId,
			OperationType::Delete,
			$entityType,
			$event->entityId,
			$event->cascadedSummary,
		);
	}
}
