<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\DeletionLogWriter;

class DeletionSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly DeletionLogWriter           $writer,
	) {}

	public function register(): void {
		$this->logEvents->subscribe( LogEvent::EntityHardDeleted, array( $this, 'handle' ) );
	}

	public function handle( EntityHardDeletedEvent $event ): void {
		$this->writer->record( $event->entityType, $event->entityId, $event->cascadedSummary );
	}
}
