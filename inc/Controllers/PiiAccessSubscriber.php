<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\PiiRevealedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\PiiAccessLogWriter;

class PiiAccessSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly PiiAccessLogWriter          $writer,
	) {}

	public function register(): void {
		$this->logEvents->subscribe( LogEvent::PiiRevealed, array( $this, 'handle' ) );
	}

	public function handle( PiiRevealedEvent $event ): void {
		$this->writer->record( $event->targetPersonId, $event->fieldsAccessed, $event->accessReason );
	}
}
