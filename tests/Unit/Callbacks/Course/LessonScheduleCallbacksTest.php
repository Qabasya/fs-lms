<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\LessonScheduleCallbacks;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\GroupCalendarService;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Services\Group\ScheduleReflowService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramRowFixtures;

/**
 * Даты КТП: раскладка, закрепление темы, календарь и свободные кабинеты.
 */
class LessonScheduleCallbacksTest extends TestCase {

	use ProgramRowFixtures;

	private ScheduleReflowService     $schedule;
	private GroupCalendarService      $calendar;
	private ProgramCompositionService $program;
	private GroupAccessGuard          $guard;
	private LessonScheduleCallbacks   $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->schedule = $this->createMock( ScheduleReflowService::class );
		$this->calendar = $this->createMock( GroupCalendarService::class );
		$this->program  = $this->createMock( ProgramCompositionService::class );
		$this->guard    = $this->createMock( GroupAccessGuard::class );

		$this->cb = new LessonScheduleCallbacks( $this->schedule, $this->calendar, $this->program, $this->guard );
	}

	public function test_reflow_schedule_delegates_when_allowed(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )->method( 'reflow' )->with( 5, $this->anything() );
		$_POST = array( 'group_id' => '5' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxReflowSchedule() )->success );
	}

	public function test_reflow_schedule_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->schedule->expects( $this->never() )->method( 'reflow' );
		$_POST = array( 'group_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxReflowSchedule() )->success );
	}

	public function test_reflow_blocked_when_program_locked(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->program->method( 'isProgramLocked' )->with( 5 )->willReturn( true );
		$this->schedule->expects( $this->never() )->method( 'reflow' );
		$_POST = array( 'group_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxReflowSchedule() )->success );
	}

	public function test_pin_lesson_delegates_to_pin_to_date(): void {
		$this->program->method( 'getProgramRow' )->with( 42 )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )->method( 'pinToDate' )->with( 42, '2026-05-20', $this->anything() );
		$_POST = array( 'group_lesson_id' => '42', 'scheduled_at' => '2026-05-20' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxPinLesson() )->success );
	}

	public function test_pin_lesson_denied_when_row_missing(): void {
		$this->program->method( 'getProgramRow' )->willReturn( null );
		$this->schedule->expects( $this->never() )->method( 'pinToDate' );
		$_POST = array( 'group_lesson_id' => '42', 'scheduled_at' => '2026-05-20' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxPinLesson() )->success );
	}

	public function test_get_group_calendar_returns_payload(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->calendar->method( 'getCalendar' )->with( 5 )->willReturn( array( 'themes' => array(), 'assigned' => true ) );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetGroupCalendar() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['assigned'] );
	}

	public function test_get_free_rooms_returns_rooms(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->calendar->expects( $this->once() )
			->method( 'freeRoomsForGroup' )
			->with( 1, '2026-05-20 15:00:00', null )
			->willReturn( array( array( 'id' => 3, 'name' => '315' ) ) );
		$_POST = array( 'group_id' => '1', 'scheduled_at' => '2026-05-20 15:00:00' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetFreeRooms() );

		self::assertTrue( $r->success );
		self::assertSame( '315', $r->payload['rooms'][0]['name'] );
	}

	public function test_get_free_rooms_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->calendar->expects( $this->never() )->method( 'freeRoomsForGroup' );
		$_POST = array( 'group_id' => '1', 'scheduled_at' => '2026-05-20 15:00:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetFreeRooms() )->success );
	}
}
