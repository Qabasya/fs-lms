<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\EnrollmentAuditLogWriter;

class EnrollmentAuditSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly EnrollmentAuditLogWriter    $writer,
	) {}

	public function register(): void {
		$handler = array( $this, 'handle' );

		$this->logEvents->subscribe( LogEvent::StudentEnrolled,   $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentFailed,  $handler );
		$this->logEvents->subscribe( LogEvent::StudentExpelled,   $handler );
		$this->logEvents->subscribe( LogEvent::StudentRestored,   $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentStarted, $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentCanceled, $handler );
	}

	public function handle( EnrollmentStatusEvent $event ): void {
		$this->writer->record(
			$event->action->value,
			'student_record',
			$event->studentRecordId,
			array_filter( array(
				'student_person_id' => $event->studentPersonId,
				'group_id'          => $event->groupId,
			) )
		);
	}
}
