<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\RoomCallbacks;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAssignmentService;
use PHPUnit\Framework\TestCase;

class RoomCallbacksTest extends TestCase {

	private $rooms;
	private $assignment;
	private $groups;
	private $subjects;
	private RoomCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->rooms      = $this->createMock( RoomRepository::class );
		$this->assignment = $this->createMock( RoomAssignmentService::class );
		$this->groups     = $this->createMock( GroupsRepository::class );
		$this->subjects   = $this->createMock( SubjectRepository::class );
		$this->cb         = new RoomCallbacks( $this->rooms, $this->assignment, $this->groups, $this->subjects );
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
		$this->rooms->expects( $this->once() )->method( 'update' )->with( 3, $this->anything() );
		$this->rooms->expects( $this->never() )->method( 'create' );
		$_POST = array( 'room_id' => '3', 'name' => 'Каб. 101', 'seats' => '30', 'is_active' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_save_room_requires_name(): void {
		$this->rooms->expects( $this->never() )->method( 'create' );
		$_POST = array( 'room_id' => '0', 'seats' => '30' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveRoom() )->success );
	}

	public function test_delete_room_soft_deletes(): void {
		$this->rooms->expects( $this->once() )->method( 'softDelete' )->with( 7 );
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
