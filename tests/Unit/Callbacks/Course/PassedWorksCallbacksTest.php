<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\PassedWorksCallbacks;
use Inc\Services\Course\PassedWorksService;
use PHPUnit\Framework\TestCase;

/** Экран «Работы»: проверка параметров ленты и маршрутизация в сервис. */
class PassedWorksCallbacksTest extends TestCase {

	private PassedWorksService&\PHPUnit\Framework\MockObject\MockObject $passed;
	private PassedWorksCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->passed = $this->createMock( PassedWorksService::class );
		$this->cb     = new PassedWorksCallbacks( $this->passed );
	}

	public function test_get_passed_works_passes_filters_and_cursor_to_service(): void {
		$this->passed->expects( $this->once() )->method( 'feed' )
			->with( $this->anything(), false, 4, 'homework', 'pending', '2026-10-11 10:00:00|11' )
			->willReturn( array( 'items' => array( array( 'work_id' => 3 ) ), 'next' => null, 'pending_total' => 2 ) );
		$_POST = array( 'group_id' => '4', 'type' => 'homework', 'status' => 'pending', 'cursor' => '2026-10-11 10:00:00|11' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetPassedWorks() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['items'][0]['work_id'] );
		self::assertSame( 2, $r->payload['pending_total'] );
	}

	public function test_get_passed_works_allows_empty_filters(): void {
		$this->passed->expects( $this->once() )->method( 'feed' )
			->with( $this->anything(), false, 0, '', '', '' )
			->willReturn( array( 'items' => array(), 'next' => null, 'pending_total' => 0 ) );
		$_POST = array();

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetPassedWorks() )->success );
	}

	public function test_get_passed_works_rejects_unknown_type(): void {
		$this->passed->expects( $this->never() )->method( 'feed' );
		$_POST = array( 'type' => 'exam' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetPassedWorks() )->success );
	}

	public function test_get_passed_works_rejects_unknown_status(): void {
		$this->passed->expects( $this->never() )->method( 'feed' );
		$_POST = array( 'status' => 'done' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetPassedWorks() )->success );
	}

	public function test_get_passed_works_reports_malformed_cursor_as_error(): void {
		$this->passed->method( 'feed' )->willThrowException( new \InvalidArgumentException( 'Некорректная метка продолжения списка.' ) );
		$_POST = array( 'cursor' => 'junk' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetPassedWorks() )->success );
	}

	public function test_get_passed_works_passes_office_flag_from_capability(): void {
		$GLOBALS['_test_user_can']   = array( 1 => array( 'manage_lms_platform' => true ) );
		$GLOBALS['_fs_test_user_id'] = 1;
		$this->passed->expects( $this->once() )->method( 'feed' )
			->with( 1, true, 0, '', '', '' )
			->willReturn( array( 'items' => array(), 'next' => null, 'pending_total' => 0 ) );
		$_POST = array();

		fs_test_capture_json( fn() => $this->cb->ajaxGetPassedWorks() );
	}
}
