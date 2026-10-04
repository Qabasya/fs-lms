<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\RoomDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Exam\ExamRoomService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Кабинет под сеанс: годность, занятость в плановое окно (UTC для сеансов, местное для занятий), поздний старт.
 * Сайт — Москва (+3): сеанс 07:00–10:55 UTC это 10:00–13:55 местного.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamRoomServiceTest extends TestCase {

	use ExamFixtures;

	private RoomRepository&MockObject $rooms;
	private ExamSessionRepository&MockObject $sessions;
	private ExamRoomService $service;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';

		$this->rooms    = $this->createMock( RoomRepository::class );
		$this->sessions = $this->createMock( ExamSessionRepository::class );
		$time           = new ExamTime( $this->createMock( ClockInterface::class ) );
		$this->service  = new ExamRoomService( $this->rooms, $this->sessions, $time, new RoomAvailabilityService( $this->rooms, $this->sessions, $time ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	/** @param array<string, mixed> $override */
	private function room( array $override = array() ): RoomDTO {
		return RoomDTO::fromArray( array_merge( array( 'id' => '2', 'name' => '305', 'seats' => '12', 'allowed_subjects' => '[]', 'is_active' => '1' ), $override ) );
	}

	private function assertRoomRefusal( callable $call, string $message ): void {
		try {
			$call();
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamRoom, $e->errorCode );
			self::assertSame( $message, $e->getMessage() );
		}
	}

	public function test_usable_room_is_returned(): void {
		$room = $this->room( array( 'allowed_subjects' => '["inf_ege"]' ) );
		$this->rooms->method( 'find' )->willReturn( $room );

		self::assertSame( $room, $this->service->assertUsable( 2, 'inf_ege' ) );
	}

	public function test_zero_seats_room_is_rejected_with_settings_hint(): void {
		$this->rooms->method( 'find' )->willReturn( $this->room( array( 'seats' => '0' ) ) );

		$this->assertRoomRefusal( fn () => $this->service->assertUsable( 2, 'inf_ege' ), 'Укажите вместимость кабинета в „Настройки → Кабинеты“.' );
	}

	public function test_missing_room_is_rejected(): void {
		$this->rooms->method( 'find' )->willReturn( null );

		$this->assertRoomRefusal( fn () => $this->service->assertUsable( 99, 'inf_ege' ), 'Кабинет не найден.' );
	}

	public function test_inactive_room_is_rejected(): void {
		$this->rooms->method( 'find' )->willReturn( $this->room( array( 'is_active' => '0' ) ) );

		$this->assertRoomRefusal( fn () => $this->service->assertUsable( 2, 'inf_ege' ), 'Кабинет отключён.' );
	}

	public function test_room_not_allowing_subject_is_rejected(): void {
		$this->rooms->method( 'find' )->willReturn( $this->room( array( 'allowed_subjects' => '["python"]' ) ) );

		$this->assertRoomRefusal( fn () => $this->service->assertUsable( 2, 'inf_ege' ), 'Кабинет не предназначен для этого предмета.' );
	}

	public function test_free_room_passes(): void {
		$this->sessions->method( 'isRoomBusy' )->willReturn( false );
		$this->rooms->method( 'isBusy' )->willReturn( false );

		$this->service->assertFree( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00' );
		$this->addToAssertionCount( 1 );
	}

	public function test_room_busy_with_another_session_is_rejected(): void {
		$this->sessions->method( 'isRoomBusy' )->willReturn( true );
		$this->rooms->method( 'isBusy' )->willReturn( false );

		try {
			$this->service->assertFree( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
			self::assertSame( 'Кабинет занят в это время.', $e->getMessage() );
		}
	}

	public function test_room_busy_with_lesson_is_rejected(): void {
		$this->sessions->method( 'isRoomBusy' )->willReturn( false );
		$this->rooms->method( 'isBusy' )->willReturn( true );

		$this->expectException( CodedException::class );

		$this->service->assertFree( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00' );
	}

	public function test_session_check_excludes_the_edited_session(): void {
		$this->sessions->expects( self::once() )->method( 'isRoomBusy' )->with( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00', 7 )->willReturn( false );
		$this->rooms->method( 'isBusy' )->willReturn( false );

		$this->service->assertFree( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00', 7 );
	}

	public function test_lesson_overlap_is_checked_in_local_time(): void {
		$this->sessions->method( 'isRoomBusy' )->willReturn( false );
		$this->rooms->expects( self::once() )->method( 'isBusy' )->with( 2, '2026-03-10 10:00:00', '2026-03-10 13:55:00' )->willReturn( false );

		$this->service->assertFree( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00' );
	}

	public function test_lock_delegates_to_room_row_lock(): void {
		$this->rooms->expects( self::once() )->method( 'lockForUpdate' )->with( 2 );

		$this->service->lock( 2 );
	}

	public function test_late_start_conflicts_lists_lesson_after_planned_end(): void {
		$session = $this->examSession( array( 'scheduled_at' => '2026-03-10 07:00:00', 'planned_end_at' => '2026-03-10 10:55:00' ) );

		$this->rooms->expects( self::once() )->method( 'listLessonsInWindow' )
			->with( 2, '2026-03-10 13:55:00', '2026-03-10 14:30:00' )
			->willReturn( array( array( 'title' => 'Занятие 10А', 'start' => '2026-03-10 14:00:00' ) ) );
		$this->sessions->method( 'listInWindowByRoom' )->willReturn( array() );

		$conflicts = $this->service->lateStartConflicts( $session, '2026-03-10 11:30:00' );

		self::assertSame( array( array( 'kind' => 'lesson', 'title' => 'Занятие 10А', 'start' => '2026-03-10 14:00:00' ) ), $conflicts );
	}

	public function test_late_start_conflicts_include_next_exam_in_local_time_sorted_by_start(): void {
		$session = $this->examSession();

		$this->rooms->method( 'listLessonsInWindow' )->willReturn( array( array( 'title' => 'Занятие', 'start' => '2026-03-10 14:20:00' ) ) );
		$this->sessions->expects( self::once() )->method( 'listInWindowByRoom' )
			->with( 2, '2026-03-10 10:55:00', '2026-03-10 11:30:00', 7 )
			->willReturn( array( array( 'title' => 'Пробный ОГЭ', 'start' => '2026-03-10 11:00:00' ) ) );

		$conflicts = $this->service->lateStartConflicts( $session, '2026-03-10 11:30:00' );

		self::assertSame( array( 'exam', 'lesson' ), array_column( $conflicts, 'kind' ) );
		self::assertSame( '2026-03-10 14:00:00', $conflicts[0]['start'], 'Начало сеанса переведено в местное время.' );
	}

	public function test_no_conflicts_when_room_empty_after_end(): void {
		$this->rooms->method( 'listLessonsInWindow' )->willReturn( array() );
		$this->sessions->method( 'listInWindowByRoom' )->willReturn( array() );

		self::assertSame( array(), $this->service->lateStartConflicts( $this->examSession(), '2026-03-10 11:30:00' ) );
	}

	public function test_no_window_when_deadline_not_after_planned_end(): void {
		$this->rooms->expects( self::never() )->method( 'listLessonsInWindow' );
		$this->sessions->expects( self::never() )->method( 'listInWindowByRoom' );

		self::assertSame( array(), $this->service->lateStartConflicts( $this->examSession(), '2026-03-10 10:55:00' ) );
	}
}
