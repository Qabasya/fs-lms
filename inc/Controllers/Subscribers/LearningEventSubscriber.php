<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\LearningEventWriter;

class LearningEventSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly LearningEventWriter         $writer,
	) {}

	public function register(): void {
		$handler = array( $this, 'handle' );

		$this->logEvents->subscribe( LogEvent::CourseAssigned,           $handler );
		$this->logEvents->subscribe( LogEvent::LessonAddedToProgram,     $handler );
		$this->logEvents->subscribe( LogEvent::LessonRemovedFromProgram, $handler );
		$this->logEvents->subscribe( LogEvent::ScheduleChanged,          $handler );
		$this->logEvents->subscribe( LogEvent::ExtraWorksChanged,        $handler );
		$this->logEvents->subscribe( LogEvent::LessonPublished,          $handler );
		$this->logEvents->subscribe( LogEvent::LessonHidden,             $handler );

		// Этап 3 — сдача работ
		$this->logEvents->subscribe( LogEvent::SubmissionMade,     $handler );
		$this->logEvents->subscribe( LogEvent::SubmissionGraded,   $handler );
		$this->logEvents->subscribe( LogEvent::SubmissionReturned, $handler );

		// Этап 4 — контрольные и экзамены
		$this->logEvents->subscribe( LogEvent::AttemptStarted,   $handler );
		$this->logEvents->subscribe( LogEvent::AttemptSubmitted,  $handler );
		$this->logEvents->subscribe( LogEvent::AttemptGraded,     $handler );
		$this->logEvents->subscribe( LogEvent::AttemptExpired,    $handler );
	}

	public function handle( LearningEvent $event ): void {
		$this->writer->record( $event );
	}
}
