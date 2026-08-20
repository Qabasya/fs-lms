<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\ReviewQueueCallbacks;
use Inc\Services\Course\ReviewQueueService;
use PHPUnit\Framework\TestCase;

/** D3 (.docs/Tasks.md): AJAX-обработчики вкладки «Работы» — авторизация + маршрутизация в сервис. */
class ReviewQueueCallbacksTest extends TestCase {

	private ReviewQueueService&\PHPUnit\Framework\MockObject\MockObject $queue;
	private ReviewQueueCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->queue = $this->createMock( ReviewQueueService::class );
		$this->cb    = new ReviewQueueCallbacks( $this->queue );
	}

	// ── ajaxGetPendingWorks ───────────────────────────────────────────────────

	public function test_get_pending_works_returns_items_for_valid_tab(): void {
		$this->queue->expects( $this->once() )
			->method( 'pendingWorks' )
			->with( $this->anything(), false, 'pending' )
			->willReturn( array( array( 'source_type' => 'work', 'source_id' => 3 ) ) );
		$_POST = array( 'tab' => 'pending' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetPendingWorks() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload[0]['source_id'] );
	}

	public function test_get_pending_works_rejects_unknown_tab(): void {
		$this->queue->expects( $this->never() )->method( 'pendingWorks' );
		$_POST = array( 'tab' => 'bogus' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetPendingWorks() )->success );
	}

	public function test_get_pending_works_passes_office_flag_from_capability(): void {
		$GLOBALS['_test_user_can'] = array( 1 => array( 'manage_lms_platform' => true ) );
		$GLOBALS['_fs_test_user_id'] = 1;
		$this->queue->expects( $this->once() )
			->method( 'pendingWorks' )
			->with( 1, true, 'done' )
			->willReturn( array() );
		$_POST = array( 'tab' => 'done' );

		fs_test_capture_json( fn() => $this->cb->ajaxGetPendingWorks() );
	}

	// ── ajaxGetWorkSubmissions ────────────────────────────────────────────────

	public function test_get_work_submissions_returns_rows(): void {
		$this->queue->expects( $this->once() )
			->method( 'submissionsFor' )
			->with( 'work', 3, $this->anything(), false, 'pending' )
			->willReturn( array( array( 'source_type' => 'submission', 'source_id' => 55 ) ) );
		$_POST = array( 'source_type' => 'work', 'source_id' => '3', 'tab' => 'pending' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkSubmissions() );

		self::assertTrue( $r->success );
		self::assertSame( 55, $r->payload[0]['source_id'] );
	}

	public function test_get_work_submissions_rejects_unknown_source_type(): void {
		$this->queue->expects( $this->never() )->method( 'submissionsFor' );
		$_POST = array( 'source_type' => 'bogus', 'source_id' => '3', 'tab' => 'pending' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkSubmissions() )->success );
	}

	public function test_get_work_submissions_rejects_unknown_tab(): void {
		$this->queue->expects( $this->never() )->method( 'submissionsFor' );
		$_POST = array( 'source_type' => 'work', 'source_id' => '3', 'tab' => 'bogus' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkSubmissions() )->success );
	}
}
