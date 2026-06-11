<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EmailSentEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\EmailLogWriter;

class EmailSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly EmailLogWriter              $writer,
	) {}

	public function register(): void {
		$this->logEvents->subscribe( LogEvent::EmailSent, array( $this, 'handle' ) );
	}

	public function handle( EmailSentEvent $event ): void {
		$this->writer->record(
			$event->emailType->value,
			$event->targetPersonId,
			$event->success,
			$event->errorMessage ?? '',
		);
	}
}
