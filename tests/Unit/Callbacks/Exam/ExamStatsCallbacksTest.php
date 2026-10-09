<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamStatsCallbacks;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamStatsService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ExamStatsCallbacksTest extends TestCase {

	private ExamStatsService&MockObject $stats;
	private ExamStatsCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 10;
		$this->stats = $this->createMock( ExamStatsService::class );
		$this->cb    = new ExamStatsCallbacks( $this->stats );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		parent::tearDown();
	}

	public function test_requires_manage_exams(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST                   = array( 'subject_key' => 'inf_ege' );
		$this->stats->expects( self::never() )->method( 'overview' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamStats() )->success );
	}

	public function test_student_role_denied(): void {
		// У ученика и родителя нет права сотрудника: прямой запрос отклоняется до сервиса.
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'manage_lms_exams' !== $cap;
		$_POST                            = array( 'subject_key' => 'inf_ege', 'event_id' => '3' );
		$this->stats->expects( self::never() )->method( 'overview' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamStats() )->success );
		unset( $GLOBALS['_fs_test_can_callback'] );
	}

	public function test_filters_are_sanitized_and_passed(): void {
		$_POST = array( 'subject_key' => 'inf_ege', 'event_id' => '3', 'session_id' => 'x', 'audience' => 'DROP' );
		$this->stats->expects( self::once() )->method( 'overview' )->with( 10, array( 'subject_key' => 'inf_ege', 'event_id' => 3, 'session_id' => 0, 'audience' => 'all' ) )
			->willReturn( array( 'filters' => array(), 'format' => null, 'kpi' => array(), 'tasks' => array() ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamStats() )->success );
	}

	public function test_foreign_event_is_refused(): void {
		$_POST = array( 'subject_key' => 'inf_ege', 'event_id' => '3' );
		$this->stats->method( 'overview' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Проведение не найдено.' ) );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamStats() )->success );
	}
}
