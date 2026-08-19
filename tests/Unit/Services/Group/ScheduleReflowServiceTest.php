<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\ScheduleReflowResultDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Group\ScheduleEventPublisher;
use Inc\Services\Group\ScheduleReflowService;
use Inc\Services\Group\SessionCalendarService;
use PHPUnit\Framework\TestCase;
use Tests\Support\GroupLessonFixtures;

/**
 * Даты КТП: постановка на дату, закрепление и раскладка.
 */
class ScheduleReflowServiceTest extends TestCase {

	use GroupLessonFixtures;

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private SessionCalendarService&\PHPUnit\Framework\MockObject\MockObject $calendar;
	private RoomAvailabilityService&\PHPUnit\Framework\MockObject\MockObject $roomAvailability;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private ScheduleReflowService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons     = $this->createMock( GroupLessonRepository::class );
		$this->groups           = $this->createMock( GroupsRepository::class );
		$this->calendar         = $this->createMock( SessionCalendarService::class );
		$this->roomAvailability = $this->createMock( RoomAvailabilityService::class );
		$this->dispatcher       = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new ScheduleReflowService(
			$this->groupLessons,
			$this->groups,
			$this->calendar,
			$this->roomAvailability,
			new ScheduleEventPublisher( $this->dispatcher ),
		);
	}

	public function test_schedule_updates_row_and_dispatches_event(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );

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

	public function test_pin_marks_row_and_dispatches_event(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );

		$this->groupLessons->expects( self::once() )->method( 'setPinned' )->with( 42, true );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->pin( 42, true, 99 );
	}

	public function test_unschedule_returns_affected_count_and_dispatches_event(): void {
		$this->groupLessons->method( 'unscheduleAll' )->with( 5 )->willReturn( 4 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		self::assertSame( 4, $this->service->unschedule( 5, 99 ) );
	}

	public function test_reflow_returns_result_from_calendar(): void {
		$expected = new ScheduleReflowResultDTO( conflicts: 3, slots: 10, consuming: 12, unplaced: 2 );
		$this->calendar->method( 'reflow' )->with( 5 )->willReturn( $expected );

		self::assertSame( $expected, $this->service->reflow( 5, 99 ) );
	}

	public function test_pin_to_date_blocks_on_room_conflict(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', 7 ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( false ); // кабинет занят
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	public function test_pin_to_date_proceeds_when_room_free(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', 7 ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/** T12.5: room-check исключает занятия СВОЕЙ группы — две темы в один день/кабинет не конфликт. */
	public function test_pin_to_date_excludes_own_group_from_room_conflict_check(): void {
		$row = $this->makeRow( 42, 'group', 7 ); // groupId: 5 (см. makeRow())
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->expects( self::once() )->method( 'isFree' )
			->with( 7, '2026-05-20 15:00:00', self::anything(), 42, $row->groupId )
			->willReturn( true );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/* ── Этап 3: строгая замена, вытесняющая занятую дату ────────────────── */

	/** Строка с датой, статусом и (опц.) continuedFromId — для сценариев вытеснения. */
	private function rowWithDate( int $id, string $scheduledAt, string $status = 'scheduled', ?int $continuedFromId = null ): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, status: $status, continuedFromId: $continuedFromId,
		);
	}

	/**
	 * Регресс-тест на зонд из разбора (Tasks.md): перетаскивание урока на дату
	 * 2-го слота больше не даёт двух уроков на одну дату — и не сдвигает
	 * остальные строки (никакого calendar->reflow() внутри pinToDate()).
	 */
	public function test_pin_to_date_evicts_row_on_same_day_without_cascading_others(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) ); // groupId: 5
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$occupant = $this->rowWithDate( 2, '2026-09-03 10:00:00' );
		$this->groupLessons->method( 'listByGroupAndDay' )->with( 5, '2026-09-03' )->willReturn( array( $occupant ) );

		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )->with( 5, '2026-09-03 10:00:00', null, self::anything() );
		$this->groupLessons->expects( self::once() )->method( 'clearSchedule' )->with( 2 );
		$this->calendar->expects( self::never() )->method( 'reflow' );

		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );
	}

	/** Дата свободна — никого вытеснять не нужно, clearSchedule() не зовётся. */
	public function test_pin_to_date_does_not_evict_when_day_is_free(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );

		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/** Проведённое занятие (held) на дате — drop отклоняется, ничего не меняется. */
	public function test_pin_to_date_rejects_when_held_lesson_occupies_the_day(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$held = $this->rowWithDate( 2, '2026-09-03 10:00:00', 'held' );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array( $held ) );

		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );
		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );
	}

	/** Индивидуальные занятия на этой дате — не вытесняются, отдельный трек. */
	public function test_pin_to_date_ignores_individual_lessons_on_the_same_day(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$individual = $this->makeRow( 2, 'individual' );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array( $individual ) );

		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );
	}

	/** T12.6: вытеснение исходной части темы уводит в пул и её продолжение — обе части вместе. */
	public function test_pin_to_date_evicts_continuation_together_with_origin(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$origin       = $this->rowWithDate( 2, '2026-09-03 10:00:00' );
		$continuation = $this->rowWithDate( 3, '2026-09-10 10:00:00', 'scheduled', 2 ); // continuedFromId: 2
		$this->groupLessons->method( 'listByGroupAndDay' )->with( 5, '2026-09-03' )->willReturn( array( $origin ) );
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( array( $origin, $continuation ) );

		$cleared = array();
		$this->groupLessons->method( 'clearSchedule' )->willReturnCallback( function ( int $id ) use ( &$cleared ) {
			$cleared[] = $id;
			return true;
		} );

		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );

		self::assertSame( array( 2, 3 ), $cleared );
	}

	/** ends_at после pinToDate() — из слота периода, а не NULL (побочный баг разбора). */
	public function test_pin_to_date_resolves_ends_at_from_generated_slot(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );
		$this->calendar->method( 'generate' )->with( 5 )->willReturn( array(
			array( 'scheduled_at' => '2026-05-20 15:00:00', 'ends_at' => '2026-05-20 16:30:00', 'room' => 0 ),
		) );

		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )
			->with( 42, '2026-05-20 15:00:00', null, '2026-05-20 16:30:00' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/** День без слота (Этап 4): ends_at считается по длительности встречи того же дня недели. */
	public function test_pin_to_date_resolves_ends_at_from_meeting_duration_when_no_slot(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->groups->method( 'getMeetings' )->willReturn( array(
			array( 'weekday' => 3, 'time' => '16:00', 'duration_min' => 90 ), // среда
		) );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );
		$this->calendar->method( 'generate' )->willReturn( array() );

		// 2026-05-20 — среда (N=3), совпадает с meeting выше → 90 минут.
		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )
			->with( 42, '2026-05-20 11:00:00', null, '2026-05-20 12:30:00' );

		$this->service->pinToDate( 42, '2026-05-20 11:00:00', 1 );
	}
}
