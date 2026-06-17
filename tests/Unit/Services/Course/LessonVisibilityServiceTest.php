<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\LogEvent;
use Inc\Managers\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\LessonVisibilityService;
use PHPUnit\Framework\TestCase;

class LessonVisibilityServiceTest extends TestCase {

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private ClockInterface&\PHPUnit\Framework\MockObject\MockObject $clock;
	private LessonVisibilityService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->clock         = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2024-06-01 12:00:00' );
		$this->service       = new LessonVisibilityService(
			$this->groupLessons,
			$this->lessonManager,
			$this->dispatcher,
			$this->clock,
		);
	}

	public function test_first_open_snapshots_work_ids(): void {
		$row    = $this->makeRow( workIdsSnapshot: null );
		$lesson = $this->makeLesson( workIds: [ 10, 20 ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->groupLessons->expects( self::once() )
			->method( 'setWorkIdsSnapshot' )
			->with( 42, [ 10, 20 ] );

		$this->service->setVisibility( 42, 'open', 1 );
	}

	public function test_first_open_passes_opened_at_to_repository(): void {
		$row    = $this->makeRow( workIdsSnapshot: null );
		$lesson = $this->makeLesson( workIds: [] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->groupLessons->expects( self::once() )
			->method( 'setVisibility' )
			->with( 42, 'open', self::matchesRegularExpression( '/\d{4}-\d{2}-\d{2}/' ) );

		$this->service->setVisibility( 42, 'open', 1 );
	}

	public function test_repeat_open_does_not_overwrite_snapshot(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 10 ] ); // already published
		$this->groupLessons->method( 'find' )->willReturn( $row );

		$this->groupLessons->expects( self::never() )->method( 'setWorkIdsSnapshot' );
		$this->lessonManager->expects( self::never() )->method( 'get' );

		$this->service->setVisibility( 42, 'open', 1 );
	}

	public function test_setting_hidden_does_not_snapshot(): void {
		$row = $this->makeRow( workIdsSnapshot: null );
		$this->groupLessons->method( 'find' )->willReturn( $row );

		$this->groupLessons->expects( self::never() )->method( 'setWorkIdsSnapshot' );

		$this->service->setVisibility( 42, 'hidden', 1 );
	}

	public function test_open_dispatches_lesson_published_event(): void {
		$row    = $this->makeRow( workIdsSnapshot: null );
		$lesson = $this->makeLesson( workIds: [] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::LessonPublished, self::anything() );

		$this->service->setVisibility( 42, 'open', 1 );
	}

	public function test_hidden_dispatches_lesson_hidden_event(): void {
		$row = $this->makeRow( workIdsSnapshot: null );
		$this->groupLessons->method( 'find' )->willReturn( $row );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::LessonHidden, self::anything() );

		$this->service->setVisibility( 42, 'hidden', 1 );
	}

	public function test_set_visibility_throws_when_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->setVisibility( 99, 'open', 1 );
	}

	public function test_refresh_from_lesson_overwrites_snapshot(): void {
		$row    = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$lesson = $this->makeLesson( workIds: [ 10, 30 ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->groupLessons->expects( self::once() )
			->method( 'setWorkIdsSnapshot' )
			->with( 42, [ 10, 30 ] );

		$this->service->refreshFromLesson( 42, 1 );
	}

	public function test_refresh_dispatches_schedule_changed_event(): void {
		$row    = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$lesson = $this->makeLesson( workIds: [] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->refreshFromLesson( 42, 1 );
	}

	public function test_refresh_throws_when_lesson_not_found(): void {
		$row = $this->makeRow( workIdsSnapshot: null );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->refreshFromLesson( 42, 1 );
	}

	// --- helpers ---

	private function makeRow( ?array $workIdsSnapshot ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 42,
			groupId         : 5,
			lessonId        : 1,
			position        : 0,
			workIdsSnapshot : $workIdsSnapshot,
			extraWorkIds    : [],
			scheduledAt     : null,
			teacherUserId   : null,
			visibility      : 'hidden',
			openedAt        : null,
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
		);
	}

	private function makeLesson( array $workIds ): LessonDTO {
		return new LessonDTO(
			id              : 1,
			subjectKey      : 'inf',
			topic           : 'Test',
			theoryHtml      : '',
			theoryArticleId : 0,
			workIds         : $workIds,
			authorId        : 1,
			status          : 'publish',
		);
	}
}
