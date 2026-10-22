<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Log;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\TrackedPage;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Log\StudentActivityLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StudentActivityLoggerTest extends TestCase {

	private LogEventDispatcherInterface&MockObject $dispatcher;
	private LearningEventRepository&MockObject $events;
	private GroupLessonRepository&MockObject $groupLessons;
	private StudentRecordRepository&MockObject $records;
	private StudentActivityLogger $logger;

	protected function setUp(): void {
		$this->dispatcher   = $this->createMock( LogEventDispatcherInterface::class );
		$this->events       = $this->createMock( LearningEventRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->logger       = new StudentActivityLogger( $this->dispatcher, $this->events, $this->groupLessons, $this->records );
	}

	public function test_lesson_opened_dispatches_private_event(): void {
		$this->events->method( 'existsWithin' )->willReturn( false );
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' )->with(
			LogEvent::LessonOpened,
			$this->callback( static fn( LearningEvent $e ): bool => 7 === $e->actorUserId && 3 === $e->groupId
				&& 'group_lesson' === $e->entityType && '15' === $e->entityId && false === $e->isPublic )
		);

		$this->logger->lessonOpened( 7, 3, 15 );
	}

	public function test_repeat_inside_window_is_skipped(): void {
		$this->events->expects( $this->once() )->method( 'existsWithin' )
			->with( LogEvent::LessonOpened->value, 7, 3, 'group_lesson', '15', StudentActivityLogger::LESSON_WINDOW_SEC )
			->willReturn( true );
		$this->dispatcher->expects( $this->never() )->method( 'dispatch' );

		$this->logger->lessonOpened( 7, 3, 15 );
	}

	public function test_page_view_is_written_for_every_active_group(): void {
		$this->events->method( 'existsWithin' )->willReturn( false );
		$this->records->method( 'findActiveByStudent' )->with( 40 )->willReturn( array( $this->record( 3 ), $this->record( 4 ) ) );
		$this->dispatcher->expects( $this->exactly( 2 ) )->method( 'dispatch' );

		$this->logger->pageViewed( 7, 40, TrackedPage::Lessons );
	}

	public function test_recording_of_unknown_lesson_is_ignored(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->dispatcher->expects( $this->never() )->method( 'dispatch' );

		self::assertFalse( $this->logger->recordingOpened( 7, 999 ) );
	}

	private function record( int $groupId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array( 'id' => $groupId, 'group_id' => $groupId, 'status' => 'active' ) );
	}
}
