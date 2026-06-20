<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Group\ScheduleService;
use Inc\Services\Group\SessionCalendarService;
use PHPUnit\Framework\TestCase;

class ScheduleServiceTest extends TestCase {

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private SessionCalendarService&\PHPUnit\Framework\MockObject\MockObject $calendar;
	private ScheduleService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->calendar      = $this->createMock( SessionCalendarService::class );
		$this->service       = new ScheduleService(
			$this->groupLessons,
			$this->lessonManager,
			$this->groups,
			$this->dispatcher,
			$this->calendar,
		);
	}

	public function test_add_lesson_inserts_row_and_returns_id(): void {
		$this->setupGroupAndLesson( 'inf', 'inf' );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 2 );
		$this->groupLessons->method( 'add' )->willReturn( 7 );

		$id = $this->service->addLesson( 1, 10, 99 );

		self::assertSame( 7, $id );
	}

	public function test_add_lesson_dispatches_lesson_added_event(): void {
		$this->setupGroupAndLesson( 'inf', 'inf' );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );
		$this->groupLessons->method( 'add' )->willReturn( 1 );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::LessonAddedToProgram, self::anything() );

		$this->service->addLesson( 1, 10, 99 );
	}

	public function test_add_lesson_rejects_cross_subject(): void {
		$this->setupGroupAndLesson( groupSubject: 'math', lessonSubject: 'inf' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->addLesson( 1, 10, 99 );
	}

	public function test_add_lesson_throws_when_group_not_found(): void {
		$this->groups->method( 'findById' )->willReturn( null );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->addLesson( 1, 10, 99 );
	}

	public function test_remove_lesson_calls_repository_and_dispatches_event(): void {
		$row = $this->makeRow();
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$this->groupLessons->expects( self::once() )->method( 'remove' )->with( 42 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::LessonRemovedFromProgram, self::anything() );

		$this->service->removeLesson( 42, 99 );
	}

	public function test_remove_lesson_silently_skips_when_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->groupLessons->expects( self::never() )->method( 'remove' );
		$this->dispatcher->expects( self::never() )->method( 'dispatch' );

		$this->service->removeLesson( 99, 1 ); // must not throw
	}

	public function test_reorder_calls_repository_and_dispatches_event(): void {
		$this->groupLessons->expects( self::once() )
			->method( 'reorder' )
			->with( 5, [ 3, 1, 2 ] );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->reorder( 5, [ 3, 1, 2 ], 99 );
	}

	public function test_schedule_updates_row_and_dispatches_event(): void {
		$row = $this->makeRow();
		$this->groupLessons->method( 'find' )->willReturn( $row );

		$this->groupLessons->expects( self::once() )
			->method( 'updateSchedule' )
			->with( 42, '2024-06-01 10:00:00', 7 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->schedule( 42, '2024-06-01 10:00:00', 7, 99 );
	}

	public function test_schedule_throws_when_row_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->schedule( 99, null, null, 1 );
	}

	public function test_get_program_returns_rows_with_topics(): void {
		$row    = $this->makeRow();
		$lesson = $this->makeLesson( 'inf' );
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( [ $row ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$program = $this->service->getProgram( 5 );

		self::assertCount( 1, $program );
		self::assertSame( 'Test lesson', $program[0]['topic'] );
		self::assertSame( $row, $program[0]['row'] );
	}

	// --- helpers ---

	private function setupGroupAndLesson( string $groupSubject, string $lessonSubject ): void {
		$group              = new \stdClass();
		$group->subject_key = $groupSubject;
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( $lessonSubject ) );
	}

	private function makeLesson( string $subjectKey ): LessonDTO {
		return new LessonDTO(
			id        : 10,
			subjectKey: $subjectKey,
			topic     : 'Test lesson',
			steps     : array(),
			authorId  : 1,
			status    : 'publish',
		);
	}

	private function makeRow(): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 42,
			groupId         : 5,
			lessonId        : 10,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : [],
			scheduledAt     : null,
			endsAt          : null,
			isPinned        : false,
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
}
