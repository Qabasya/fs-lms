<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\PersonDataChangedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\DataChangeLogWriter;

class DataChangeSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly DataChangeLogWriter         $writer,
	) {}

	public function register(): void {
		$this->logEvents->subscribe( LogEvent::PersonDataChanged, array( $this, 'handle' ) );
	}

	public function handle( PersonDataChangedEvent $event ): void {
		$this->writer->record(
			$event->targetPersonId,
			$event->fieldName,
			null !== $event->oldValue ? (string) $event->oldValue : null,
			null !== $event->newValue ? (string) $event->newValue : null,
		);
	}
}
