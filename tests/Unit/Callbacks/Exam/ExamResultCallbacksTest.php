<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamResultCallbacks;
use Inc\Services\Exam\ExamResultsViewService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AJAX «Результатов»: право и nonce, очистка фильтров.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamResultCallbacksTest extends TestCase {

	private ExamResultsViewService&MockObject $results;
	private ExamResultCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 10;

		$this->results = $this->createMock( ExamResultsViewService::class );
		$this->cb      = new ExamResultCallbacks( $this->results );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		parent::tearDown();
	}

	public function test_requires_manage_exams(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST                   = array( 'subject_key' => 'inf_ege' );
		$this->results->expects( self::never() )->method( 'results' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamResults() )->success );
	}

	public function test_filters_are_sanitized(): void {
		$_POST = array(
			'subject_key' => 'inf_ege', 'event_id' => '3', 'session_id' => '-7', 'source_id' => 'abc',
			'status' => 'DROP', 'audience' => 'guest',
		);
		$this->results->expects( self::once() )->method( 'results' )->with(
			10,
			'inf_ege',
			array( 'event_id' => 3, 'session_id' => 7, 'source_id' => 0, 'status' => 'all', 'audience' => 'guest' )
		)->willReturn( array( 'filters' => array(), 'items' => array() ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamResults() )->success );
	}
}
