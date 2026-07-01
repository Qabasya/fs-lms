<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\SubstitutionCallbacks;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\RoomAssignmentService;
use Inc\Services\Course\SubstitutionService;
use PHPUnit\Framework\TestCase;

class SubstitutionCallbacksTest extends TestCase {

	private SubstitutionService&\PHPUnit\Framework\MockObject\MockObject $service;
	private RoomAssignmentService&\PHPUnit\Framework\MockObject\MockObject $roomAssignment;
	private SubstitutionCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service        = $this->createMock( SubstitutionService::class );
		$this->roomAssignment = $this->createMock( RoomAssignmentService::class );
		$this->cb             = new SubstitutionCallbacks(
			$this->service,
			$this->createMock( UserRepository::class ),
			$this->createMock( RoomRepository::class ),
			$this->roomAssignment,
		);
	}

	public function test_set_room_override_delegates(): void {
		$this->roomAssignment->expects( $this->once() )
			->method( 'overrideForRange' )
			->with( 7, 3, '2026-05-01', '2026-05-14' )
			->willReturn( array( 'applied' => 4, 'skipped' => 0, 'warnings' => array() ) );
		$_POST = array( 'group_id' => '7', 'room_id' => '3', 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-14' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSetRoomOverride() );

		self::assertTrue( $r->success );
		self::assertSame( 4, $r->payload['applied'] );
	}

	public function test_set_room_override_requires_period(): void {
		$this->roomAssignment->expects( $this->never() )->method( 'overrideForRange' );
		$_POST = array( 'group_id' => '7', 'room_id' => '3' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSetRoomOverride() )->success );
	}

	public function test_assign_delegates_and_returns_id(): void {
		$this->service->expects( $this->once() )
			->method( 'assign' )
			->with( 7, 55, '2026-05-01', '2026-05-31', 'Отпуск', $this->anything() )
			->willReturn( 3 );
		$_POST = array(
			'group_id'              => '7',
			'substitute_teacher_id' => '55',
			'valid_from'            => '2026-05-01',
			'valid_to'              => '2026-05-31',
			'reason'                => 'Отпуск',
		);

		$r = fs_test_capture_json( fn() => $this->cb->ajaxAssignSubstitute() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['substitution_id'] );
	}

	public function test_assign_surfaces_service_error(): void {
		$this->service->method( 'assign' )
			->willThrowException( new \InvalidArgumentException( 'Дата начала позже даты окончания.' ) );
		$_POST = array(
			'group_id'              => '7',
			'substitute_teacher_id' => '55',
			'valid_from'            => '2026-05-31',
			'valid_to'              => '2026-05-01',
		);

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAssignSubstitute() )->success );
	}

	public function test_revoke_delegates(): void {
		$this->service->expects( $this->once() )->method( 'revoke' )->with( 3 );
		$_POST = array( 'substitution_id' => '3' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxRevokeSubstitute() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['substitution_id'] );
	}
}
