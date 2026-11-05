<?php
declare( strict_types=1 );
namespace Unit\Callbacks\Profile;
use Inc\Callbacks\Profile\SelfPacedEnrollmentCallbacks;
use Inc\Services\Enrollment\SelfPacedEnrollmentService;
use PHPUnit\Framework\TestCase;
class SelfPacedEnrollmentCallbacksTest extends TestCase {
	private SelfPacedEnrollmentService $service;
	private SelfPacedEnrollmentCallbacks $callback;
	protected function setUp(): void {
		fs_test_reset_ajax();
		$GLOBALS['_test_logged_in'] = true;
		$GLOBALS['_fs_test_user_id'] = 7;
		$this->service = $this->createMock( SelfPacedEnrollmentService::class );
		$this->callback = new SelfPacedEnrollmentCallbacks( $this->service );
	}
	public function test_uses_current_user_and_ignores_spoofed_person_group_child(): void {
		$_POST = array( 'course_id' => '15', 'person_id' => 888, 'group_id' => 999, 'student_person_id' => 666 );
		$this->service->expects( self::once() )->method( 'start' )->with( 15, 7 )->willReturn( array( 'course_id' => 15, 'group_id' => 27, 'record_id' => 9 ) );
		self::assertTrue( fs_test_capture_json( fn() => $this->callback->ajaxStartSelfPacedCourse() )->success );
	}
	public function test_rejects_malformed_identifiers_before_service(): void {
		$this->service->expects( self::never() )->method( 'start' );
		foreach ( array( array( '15' ), '-15', '15foo', '0', '1.5', '9999999999999999999999999999' ) as $value ) {
			$_POST = array( 'course_id' => $value );
			self::assertFalse( fs_test_capture_json( fn() => $this->callback->ajaxStartSelfPacedCourse() )->success );
		}
	}
	public function test_storage_error_returns_generic_failure_without_identifiers_or_success_payload(): void {
		$_POST = array( 'course_id' => '15' );
		$this->service->method( 'start' )->willThrowException( new \RuntimeException( 'private SQL error' ) );
		$response = fs_test_capture_json( fn() => $this->callback->ajaxStartSelfPacedCourse() );
		self::assertFalse( $response->success );
		self::assertStringNotContainsString( 'private SQL', json_encode( $response->payload ) );
	}
	public function test_missing_capability_cannot_start(): void {
		$_POST = array( 'course_id' => '15' ); $GLOBALS['_fs_test_can'] = false;
		$this->service->expects( self::never() )->method( 'start' );
		self::assertFalse( fs_test_capture_json( fn() => $this->callback->ajaxStartSelfPacedCourse() )->success );
	}
}
