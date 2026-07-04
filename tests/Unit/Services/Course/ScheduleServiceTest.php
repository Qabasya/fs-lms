<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Group\ScheduleService;
use Inc\Services\Group\SessionCalendarService;
use PHPUnit\Framework\TestCase;

class ScheduleServiceTest extends TestCase {

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private SessionCalendarService&\PHPUnit\Framework\MockObject\MockObject $calendar;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private RoomAvailabilityService&\PHPUnit\Framework\MockObject\MockObject $roomAvailability;
	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courses;
	private ScheduleService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->calendar      = $this->createMock( SessionCalendarService::class );
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->roomAvailability = $this->createMock( RoomAvailabilityService::class );
		$this->courses       = $this->createMock( CourseManager::class );
		$this->service       = new ScheduleService(
			$this->groupLessons,
			$this->lessonManager,
			$this->groups,
			$this->dispatcher,
			$this->calendar,
			$this->records,
			$this->rooms,
			$this->roomAvailability,
			$this->courses,
		);
	}

	public function test_create_individual_lesson_inserts_individual_pinned_row(): void {
		$group = new \stdClass();
		$this->groups->method( 'findById' )->with( 1 )->willReturn( $group );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )
			->willReturn( array( (object) array( 'studentPersonId' => 9001 ) ) );

		$this->groupLessons->expects( self::once() )
			->method( 'add' )
			->with( self::callback(
				static fn( $dto ) => 'individual' === $dto->kind
					&& 9001 === $dto->studentPersonId
					&& true === $dto->isPinned
					&& '2026-05-20 15:00:00' === $dto->scheduledAt
			) )
			->willReturn( 15 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$id = $this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99 );
		self::assertSame( 15, $id );
	}

	public function test_create_individual_lesson_rejects_non_member(): void {
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99 );
	}

	public function test_get_program_excludes_individual_lessons(): void {
		$group      = $this->makeRow( 42, 'group' );
		$individual = $this->makeRow( 43, 'individual' );
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( array( $group, $individual ) );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$program = $this->service->getProgram( 5 );

		self::assertCount( 1, $program );
		self::assertSame( 42, $program[0]['row']->id );
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

	public function test_add_lesson_allows_cross_subject_and_pins_with_label(): void {
		// Доп. занятие из другого предмета (Python в ЕГЭ-группу) теперь разрешено.
		$this->setupGroupAndLesson( groupSubject: 'ege', lessonSubject: 'python' );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 3 );
		$this->groupLessons->expects( self::once() )
			->method( 'add' )
			->with( self::callback(
				static fn( $dto ) => true === $dto->isPinned
					&& 'Доп. Python #1' === $dto->label
					&& 10 === $dto->lessonId
			) )
			->willReturn( 8 );

		self::assertSame( 8, $this->service->addLesson( 1, 10, 99, 'Доп. Python #1' ) );
	}

	public function test_duplicate_lesson_inserts_pinned_copy_and_dispatches(): void {
		$row = $this->makeRow();
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $row );
		$this->groupLessons->method( 'nextPosition' )->with( 5 )->willReturn( 4 );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$this->groupLessons->expects( self::once() )
			->method( 'add' )
			->with( self::callback(
				static fn( $dto ) => 5 === $dto->groupId && 10 === $dto->lessonId && true === $dto->isPinned
			) )
			->willReturn( 12 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::LessonAddedToProgram, self::anything() );

		self::assertSame( 12, $this->service->duplicateLesson( 42, 99 ) );
	}

	public function test_duplicate_lesson_returns_zero_when_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		self::assertSame( 0, $this->service->duplicateLesson( 99, 1 ) );
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

	private function makeRow( int $id = 42, string $kind = 'group', ?int $roomId = null, ?int $continuedFromId = null, ?int $lessonId = 10 ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : $id,
			groupId         : 5,
			lessonId        : $lessonId,
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
			kind            : $kind,
			roomId          : $roomId,
			continuedFromId : $continuedFromId,
		);
	}

	public function test_pin_to_date_blocks_on_room_conflict(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', 7 ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( false ); // кабинет занят
		$this->groupLessons->expects( $this->never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	public function test_pin_to_date_proceeds_when_room_free(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', 7 ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->expects( $this->once() )->method( 'updateSchedule' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/** T12.5: room-check исключает занятия СВОЕЙ группы — две темы в один день/кабинет не конфликт. */
	public function test_pin_to_date_excludes_own_group_from_room_conflict_check(): void {
		$row = $this->makeRow( 42, 'group', 7 ); // groupId: 5 (см. makeRow())
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->expects( $this->once() )->method( 'isFree' )
			->with( 7, '2026-05-20 15:00:00', $this->anything(), 42, $row->groupId )
			->willReturn( true );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/* ── Продолжение темы (T12.6, D14) ───────────────────────────────────── */

	private function stubCalendarDeps(): void {
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period' => null, 'holidays' => array(), 'lessonDays' => array(), 'lessonTimes' => array(),
		) );
		$this->lessonManager->method( 'get' )->willReturn( null );
		$this->rooms->method( 'findAll' )->willReturn( array() );
	}

	public function test_get_calendar_gives_continuation_pair_shared_number_and_parts(): void {
		$this->stubCalendarDeps();
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->makeRow( 10, 'group', null, null ),   // origin
			$this->makeRow( 11, 'group', null, 10 ),      // continuation of 10
			$this->makeRow( 12, 'group', null, null ),   // standalone theme
		) );

		$themes = $this->service->getCalendar( 5 )['themes'];

		self::assertSame( 1, $themes[0]['n'] );
		self::assertSame( 1, $themes[0]['part'] );
		self::assertSame( 2, $themes[0]['total_parts'] );

		self::assertSame( 1, $themes[1]['n'] ); // тот же номер — общая тема
		self::assertSame( 2, $themes[1]['part'] );
		self::assertSame( 2, $themes[1]['total_parts'] );

		self::assertSame( 2, $themes[2]['n'] ); // следующая отдельная тема
		self::assertSame( 1, $themes[2]['part'] );
		self::assertSame( 1, $themes[2]['total_parts'] );
	}

	/** Продолжение с удалённым (отсутствующим) оригиналом деградирует до самостоятельной темы. */
	public function test_get_calendar_orphan_continuation_degrades_to_standalone(): void {
		$this->stubCalendarDeps();
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->makeRow( 20, 'group', null, 999 ), // continuedFromId=999 — такой строки нет
		) );

		$themes = $this->service->getCalendar( 5 )['themes'];

		self::assertSame( 1, $themes[0]['n'] );
		self::assertSame( 1, $themes[0]['part'] );
		self::assertSame( 1, $themes[0]['total_parts'] );
	}

	public function test_continue_lesson_creates_pinned_row_with_link(): void {
		$origin = $this->makeRow( 42, 'group', null, null, 10 );
		$this->groupLessons->method( 'find' )->willReturn( $origin );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 3 );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );
		$this->groupLessons->expects( $this->once() )->method( 'add' )
			->with( $this->callback( fn( $dto ) =>
				true === $dto->isPinned && 42 === $dto->continuedFromId && null === $dto->scheduledAt
			) )
			->willReturn( 43 );

		self::assertSame( 43, $this->service->continueLesson( 42, 99 ) );
	}

	public function test_continue_lesson_rejects_continuing_a_continuation(): void {
		$alreadyContinuation = $this->makeRow( 11, 'group', null, 10 ); // continuedFromId=10
		$this->groupLessons->method( 'find' )->willReturn( $alreadyContinuation );
		$this->groupLessons->expects( $this->never() )->method( 'add' );

		self::assertSame( 0, $this->service->continueLesson( 11, 99 ) );
	}

	public function test_continue_lesson_returns_zero_when_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->groupLessons->expects( $this->never() )->method( 'add' );

		self::assertSame( 0, $this->service->continueLesson( 999, 1 ) );
	}
}
