<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\RoomCallbacks;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAssignmentService;
use Inc\Services\Exam\ExamEventService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\TestCase;

class RoomCallbacksTest extends TestCase {

	private $rooms;
	private $assignment;
	private $groups;
	private $subjects;
	private $exams;
	private RoomCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->rooms      = $this->createMock( RoomRepository::class );
		$this->assignment = $this->createMock( RoomAssignmentService::class );
		$this->groups     = $this->createMock( GroupsRepository::class );
		$this->subjects   = $this->createMock( SubjectRepository::class );
		$this->exams      = $this->createMock( ExamEventService::class );
		$this->cb         = new RoomCallbacks( $this->rooms, $this->assignment, $this->groups, $this->subjects, $this->exams );
	}

	public function test_save_room_creates_when_no_id(): void {
		$this->rooms->expects( $this->once() )->method( 'create' )->willReturn( 5 );
		$this->rooms->expects( $this->never() )->method( 'update' );
		$_POST = array( 'room_id' => '0', 'name' => 'Каб. 101', 'seats' => '30', 'is_active' => '1', 'allowed_subjects' => array( 'inf' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() );

		self::assertTrue( $r->success );
		self::assertSame( 5, $r->payload['room_id'] );
	}

	public function test_save_room_updates_when_id_present(): void {
		$this->exams->method( 'syncCapacityForRoom' )->willReturnCallback( static fn ( int $room, int $seats, ?callable $persist ) => $persist() );
		$this->rooms->expects( $this->once() )->method( 'update' )->with( 3, $this->anything() );
		$this->rooms->expects( $this->never() )->method( 'create' );
		$_POST = array( 'room_id' => '3', 'name' => 'Каб. 101', 'seats' => '30', 'is_active' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_save_room_syncs_exam_capacity_with_new_seats_before_saving(): void {
		$order = array();
		$this->exams->expects( $this->once() )->method( 'syncCapacityForRoom' )->with( 3, 25, $this->isCallable() )
			->willReturnCallback( function ( int $room, int $seats, ?callable $persist ) use ( &$order ): void {
				$order[] = 'sync';
				$persist();
			} );
		$this->rooms->method( 'update' )->willReturnCallback( function () use ( &$order ): bool {
			$order[] = 'save';
			return true;
		} );
		$_POST = array( 'room_id' => '3', 'name' => 'Каб. 101', 'seats' => '25' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
		self::assertSame( array( 'sync', 'save' ), $order );
	}

	public function test_save_room_is_blocked_when_capacity_sync_fails(): void {
		$this->exams->method( 'syncCapacityForRoom' )
			->willThrowException( new CodedException( ErrorCode::ExamConflict, 'Нельзя уменьшить вместимость до 8: в проведении «Пробный ЕГЭ» уже занято 9 мест.' ) );
		$this->rooms->expects( $this->never() )->method( 'update' );
		$_POST = array( 'room_id' => '3', 'name' => 'Каб. 101', 'seats' => '8' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() );

		self::assertFalse( $r->success );
		self::assertSame( 'Нельзя уменьшить вместимость до 8: в проведении «Пробный ЕГЭ» уже занято 9 мест.', $r->payload['message'] );
		self::assertSame( 'X-CONFLICT', $r->payload['code'] );
	}

	public function test_save_room_without_seats_field_skips_capacity_sync(): void {
		$this->exams->expects( $this->never() )->method( 'syncCapacityForRoom' );
		$this->rooms->expects( $this->once() )->method( 'update' )->with( 3, $this->callback( static fn ( array $d ): bool => ! isset( $d['seats'] ) ) );
		$_POST = array( 'room_id' => '3', 'name' => 'Каб. 101' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_new_room_has_no_sessions_to_sync(): void {
		$this->exams->expects( $this->never() )->method( 'syncCapacityForRoom' );
		$this->rooms->expects( $this->once() )->method( 'create' )->willReturn( 9 );
		$_POST = array( 'room_id' => '0', 'name' => 'Каб. 7', 'seats' => '20' );

		self::assertSame( 9, fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->payload['room_id'] );
	}

	public function test_save_room_passes_seats_to_repository(): void {
		$this->rooms->expects( $this->once() )->method( 'create' )->with( $this->callback( static fn ( array $d ): bool => 20 === $d['seats'] ) )->willReturn( 5 );
		$_POST = array( 'room_id' => '0', 'name' => '315', 'seats' => '20' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_save_room_keeps_seats_when_param_absent_on_update(): void {
		$this->exams->method( 'syncCapacityForRoom' )->willReturnCallback( static fn ( int $room, int $seats, ?callable $persist ) => $persist() );
		$this->rooms->expects( $this->once() )->method( 'update' )->with( 3, $this->callback( static fn ( array $d ): bool => ! array_key_exists( 'seats', $d ) ) );
		$_POST = array( 'room_id' => '3', 'name' => 'Новое имя' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_save_room_clamps_negative_seats_to_zero(): void {
		$this->rooms->expects( $this->once() )->method( 'create' )->with( $this->callback( static fn ( array $d ): bool => 0 === $d['seats'] ) )->willReturn( 6 );
		$_POST = array( 'room_id' => '0', 'name' => '316', 'seats' => '-5' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_save_room_requires_name(): void {
		$this->rooms->expects( $this->never() )->method( 'create' );
		$_POST = array( 'room_id' => '0', 'seats' => '30' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_delete_room_hard_deletes(): void {
		// Каскадная очистка: удаление кабинета — hard-delete + отвязка от всех групп.
		$this->assignment->expects( $this->once() )->method( 'unassignFromAll' )->with( 7 );
		$this->rooms->expects( $this->once() )->method( 'hardDelete' )->with( 7 );
		$_POST = array( 'room_id' => '7' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxDeleteRoom() )->success );
	}

	public function test_assign_group_room_delegates(): void {
		$this->assignment->expects( $this->once() )->method( 'assignToGroup' )->with( 7, 3 )->willReturn( array( 'мест мало' ) );
		$_POST = array( 'group_id' => '7', 'room_id' => '3' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxAssignGroupRoom() );

		self::assertTrue( $r->success );
		self::assertSame( array( 'мест мало' ), $r->payload['warnings'] );
	}

	public function test_assign_group_room_surfaces_error(): void {
		$this->assignment->method( 'assignToGroup' )->willThrowException( new \InvalidArgumentException( 'Кабинет не найден.' ) );
		$_POST = array( 'group_id' => '7', 'room_id' => '99' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAssignGroupRoom() )->success );
	}
}
