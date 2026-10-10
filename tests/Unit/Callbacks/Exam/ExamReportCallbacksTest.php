<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamReportCallbacks;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamReportService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\TestCase;

/**
 * AJAX отчётов: право `ShareExamResults`, область проведения (проверяет сервис); ссылка не требует экспортных прав.
 */
class ExamReportCallbacksTest extends TestCase {

	private ExamReportService $service;
	private ExamReportCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$_POST         = array();
		$this->service = $this->createMock( ExamReportService::class );
		$this->cb      = new ExamReportCallbacks( $this->service );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_can_callback'] );
		$_POST = array();
		parent::tearDown();
	}

	public function test_link_issue_does_not_require_export_pii(): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'share_lms_exam_results' === $cap;
		$_POST = array( 'report_id' => '7' );
		$this->service->expects( self::once() )->method( 'issueLink' )->willReturn( 'https://x.test/exam-report/?k=abc' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxIssueExamReportLink() );

		self::assertTrue( $r->success );
		self::assertSame( 'https://x.test/exam-report/?k=abc', $r->payload['url'] );
	}

	public function test_denied_without_share_cap(): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'share_lms_exam_results' !== $cap;
		$_POST = array( 'report_id' => '7', 'event_id' => '3', 'title' => 'x' );
		$this->service->expects( self::never() )->method( 'issueLink' );
		$this->service->expects( self::never() )->method( 'create' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxIssueExamReportLink() )->success );
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveExamReport() )->success );
	}

	public function test_denied_for_foreign_event(): void {
		$_POST = array( 'event_id' => '3' );
		$this->service->method( 'overview' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Проведение не найдено.' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamReports() );

		self::assertFalse( $r->success );
		self::assertSame( ErrorCode::ExamAccess->value, $r->payload['code'] );
	}

	public function test_save_passes_selection_and_returns_overview(): void {
		$_POST = array( 'event_id' => '3', 'title' => 'Школа 5', 'participation_ids' => array( '1', '2' ), 'recipient_source_id' => '14', 'days' => '30' );
		$this->service->expects( self::once() )->method( 'create' )->with( self::anything(), 3, 'Школа 5', array( 1, 2 ), 14, 30 )
			->willReturn( ExamReportDTO::fromArray( array( 'id' => 7, 'event_id' => 3, 'title' => 'Школа 5', 'owner_user_id' => 1, 'expires_at' => '2026-06-01 00:00:00', 'version' => 1, 'created_at' => '2026-03-01 00:00:00' ) ) );
		$this->service->method( 'overview' )->willReturn( array( 'reports' => array(), 'blocked' => array(), 'sources' => array() ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveExamReport() );

		self::assertTrue( $r->success );
		self::assertSame( 7, $r->payload['report_id'] );
	}
}
