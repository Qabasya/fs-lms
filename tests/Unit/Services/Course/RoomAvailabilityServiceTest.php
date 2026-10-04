<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\RoomDTO;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Единственная точка «свободен ли кабинет»: занятия (местное время) и сеансы экзаменов (UTC; сайт — Москва, +3).
 */
#[AllowMockObjectsWithoutExpectations]
class RoomAvailabilityServiceTest extends TestCase {

	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private ExamSessionRepository&\PHPUnit\Framework\MockObject\MockObject $examSessions;
	private RoomAvailabilityService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$this->rooms        = $this->createMock( RoomRepository::class );
		$this->examSessions = $this->createMock( ExamSessionRepository::class );
		$this->service      = new RoomAvailabilityService( $this->rooms, $this->examSessions, new ExamTime( $this->createMock( ClockInterface::class ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	public function test_is_free_negates_is_busy(): void {
		$this->rooms->method( 'isBusy' )->willReturn( false );
		self::assertTrue( $this->service->isFree( 1, '2026-05-20 09:00:00', '2026-05-20 10:00:00' ) );
	}

	/** T12.5: excludeGroupId пробрасывается в RoomRepository::isBusy() как есть. */
	public function test_is_free_passes_exclude_group_id_through(): void {
		$this->rooms->expects( $this->once() )->method( 'isBusy' )
			->with( 1, '2026-05-20 09:00:00', '2026-05-20 10:00:00', 42, 5 )
			->willReturn( false );

		$this->service->isFree( 1, '2026-05-20 09:00:00', '2026-05-20 10:00:00', 42, 5 );
	}

	public function test_list_free_rooms_filters_by_subject_and_busy(): void {
		$this->rooms->method( 'findAll' )->with( true )->willReturn( array(
			new RoomDTO( 1, 'Инф-1', 20, array( 'inf' ), true ),
			new RoomDTO( 2, 'Рус-1', 20, array( 'rus' ), true ), // не тот предмет
			new RoomDTO( 3, 'Инф-2', 20, array( 'inf' ), true ), // занят
		) );
		$this->rooms->method( 'isBusy' )->willReturnCallback( static fn( $id ) => 3 === $id );

		$free = $this->service->listFreeRooms( '2026-05-20 09:00:00', '2026-05-20 10:00:00', 'inf' );

		self::assertCount( 1, $free );
		self::assertSame( 1, $free[0]->id );
	}
	public function test_room_busy_by_exam_session_is_not_free_for_lesson(): void {
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->examSessions->method( 'isRoomBusy' )->willReturn( true );

		self::assertFalse( $this->service->isFree( 1, '2026-05-20 10:00:00', '2026-05-20 11:00:00' ) );
	}

	public function test_exam_window_is_converted_to_utc(): void {
		// Занятие 10:00–11:00 МСК → сеансы сверяются в UTC: 07:00–08:00.
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->examSessions->expects( $this->once() )->method( 'isRoomBusy' )->with( 1, '2026-05-20 07:00:00', '2026-05-20 08:00:00' )->willReturn( false );

		self::assertTrue( $this->service->isFree( 1, '2026-05-20 10:00:00', '2026-05-20 11:00:00' ) );
	}

	public function test_room_free_right_after_planned_end(): void {
		// Экзамен 10:00–13:55 МСК (07:00–10:55 UTC); занятие в 13:55 встык разрешено: база считает окно строго [начало, конец).
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->examSessions->method( 'isRoomBusy' )->willReturnCallback(
			static fn ( int $room, string $start, string $end ): bool => $start < '2026-05-20 10:55:00' && $end > '2026-05-20 07:00:00'
		);

		self::assertTrue( $this->service->isFree( 1, '2026-05-20 13:55:00', '2026-05-20 14:55:00' ) );
		self::assertFalse( $this->service->isFree( 1, '2026-05-20 13:54:00', '2026-05-20 14:54:00' ), 'На минуту раньше — уже пересекается.' );
	}

	public function test_edited_exam_session_is_excluded_from_its_own_conflict(): void {
		// Правка сеанса не конфликтует с самим сеансом: идентификатор уходит в проверку занятости сеансов.
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->examSessions->expects( $this->once() )->method( 'isRoomBusy' )->with( 1, '2026-05-20 07:00:00', '2026-05-20 08:00:00', 55 )->willReturn( false );

		self::assertTrue( $this->service->isFree( 1, '2026-05-20 10:00:00', '2026-05-20 11:00:00', 0, 0, 55 ) );
	}

	public function test_lesson_conflict_still_blocks_even_without_exam(): void {
		$this->rooms->method( 'isBusy' )->willReturn( true );
		$this->examSessions->method( 'isRoomBusy' )->willReturn( false );

		self::assertFalse( $this->service->isFree( 1, '2026-05-20 10:00:00', '2026-05-20 11:00:00' ) );
	}

	public function test_exclusions_apply_to_lessons_not_to_exams(): void {
		$this->rooms->expects( $this->once() )->method( 'isBusy' )->with( 1, '2026-05-20 10:00:00', '2026-05-20 11:00:00', 42, 5 )->willReturn( false );
		$this->examSessions->expects( $this->once() )->method( 'isRoomBusy' )->with( 1, '2026-05-20 07:00:00', '2026-05-20 08:00:00' )->willReturn( false );

		$this->service->isFree( 1, '2026-05-20 10:00:00', '2026-05-20 11:00:00', 42, 5 );
	}

	public function test_list_free_rooms_excludes_room_with_exam(): void {
		$this->rooms->method( 'findAll' )->with( true )->willReturn( array(
			new RoomDTO( 1, 'Инф-1', 20, array(), true ),
			new RoomDTO( 2, 'Инф-2', 20, array(), true ),
		) );
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->examSessions->method( 'isRoomBusy' )->willReturnCallback( static fn ( int $id ): bool => 2 === $id );

		$free = $this->service->listFreeRooms( '2026-05-20 10:00:00', '2026-05-20 11:00:00' );

		self::assertSame( array( 1 ), array_map( static fn ( RoomDTO $r ): int => $r->id, $free ) );
	}
}
