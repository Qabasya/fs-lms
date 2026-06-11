<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\ConsentChangedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\ConsentChangeLogWriter;

class ConsentChangeSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly ConsentChangeLogWriter      $writer,
	) {}

	public function register(): void {
		$this->logEvents->subscribe( LogEvent::ConsentChanged, array( $this, 'handle' ) );
	}

	public function handle( ConsentChangedEvent $event ): void {
		$this->writer->record( $event->personId, $event->consentType, $event->oldHash, $event->newHash );
	}
}
