<?php

declare( strict_types=1 );

namespace Tests\Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamUnregisteredCallbacks;
use Inc\Services\Exam\ExamUnregisteredService;
use Inc\Services\Export\ExportService;
use PHPUnit\Framework\TestCase;

class ExamUnregisteredCallbacksTest extends TestCase {
	private ExamUnregisteredService $students;
	private ExamUnregisteredCallbacks $callbacks;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 10;
		$this->students = $this->createMock( ExamUnregisteredService::class );
		$this->callbacks = new ExamUnregisteredCallbacks( $this->students, $this->createStub( ExportService::class ) );
	}

	protected function tearDown(): void {
		fs_test_reset_ajax();
		parent::tearDown();
	}

	public function test_emergency_picker_passes_explicit_admission_mode(): void {
		$_POST = array( 'session_id' => '7', 'for_admission' => '1' );
		$this->students->expects( self::once() )->method( 'rows' )->with( 10, 7, true )->willReturn( array( array( 'person_id' => 3 ) ) );
		$response = fs_test_capture_json( fn() => $this->callbacks->ajaxGetExamUnregistered() );
		self::assertTrue( $response->success );
		self::assertSame( array( array( 'person_id' => 3 ) ), $response->payload['rows'] );
	}

	public function test_report_uses_the_original_unregistered_mode(): void {
		$_POST = array( 'session_id' => '7' );
		$this->students->expects( self::once() )->method( 'rows' )->with( 10, 7, false )->willReturn( array() );
		self::assertTrue( fs_test_capture_json( fn() => $this->callbacks->ajaxGetExamUnregistered() )->success );
	}

	public function test_admission_mode_does_not_bypass_permissions_or_nonce(): void {
		$_POST = array( 'session_id' => '7', 'for_admission' => '1' );
		$this->students->expects( self::never() )->method( 'rows' );
		$GLOBALS['_fs_test_can'] = false;
		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxGetExamUnregistered() )->success );
		$GLOBALS['_fs_test_can'] = true;
		$GLOBALS['_fs_test_nonce_ok'] = false;
		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxGetExamUnregistered() )->success );
	}
}
