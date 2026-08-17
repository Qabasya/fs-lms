<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
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

	public function test_reflow_returns_conflict_count_from_calendar(): void {
		$this->calendar->method( 'reflow' )->with( 5 )->willReturn( 3 );

		self::assertSame( 3, $this->service->reflow( 5, 99 ) );
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
}
