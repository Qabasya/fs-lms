<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamEventCallbacks;
use Inc\Callbacks\Exam\ExamPaymentQueueCallbacks;
use Inc\Enums\Exam\ManualResolutionKind;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamEventService;
use Inc\Services\Exam\ExamPaymentQueueService;
use Inc\Services\Exam\ExamPlanService;
use Inc\Services\Exam\GuestApplicationService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Очередь оплат: достаточно любого из прав `ResolveExamPayments` / `ManageExams`; офис не получает управления проведениями.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamPaymentQueueCallbacksTest extends TestCase {

	private ExamPaymentQueueService&MockObject $queue;
	private GuestApplicationService&MockObject $applications;
	private ExamPaymentQueueCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$_POST              = array();
		$this->queue        = $this->createMock( ExamPaymentQueueService::class );
		$this->applications = $this->createMock( GuestApplicationService::class );
		$this->cb           = new ExamPaymentQueueCallbacks( $this->queue, $this->applications );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_can_callback'] );
		$_POST = array();
		parent::tearDown();
	}

	private function only( string ...$caps ): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => in_array( $cap, $caps, true );
	}

	public function test_office_with_resolve_cap_sees_queue(): void {
		$this->only( 'resolve_lms_exam_payments' );
		$this->queue->expects( self::once() )->method( 'list' )->with( self::anything(), 'needs_help' )->willReturn( array( 'items' => array() ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamPaymentQueue() )->success );
	}

	public function test_teacher_with_manage_exams_sees_queue(): void {
		$this->only( 'manage_lms_exams' );
		$_POST = array( 'tab' => 'resolved' );
		$this->queue->expects( self::once() )->method( 'list' )->with( self::anything(), 'resolved' )->willReturn( array( 'items' => array() ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamPaymentQueue() )->success );
	}

	public function test_user_without_both_caps_denied(): void {
		$this->only( 'manage_lms_platform' );
		$this->queue->expects( self::never() )->method( 'list' );
		$this->applications->expects( self::never() )->method( 'resolve' );
		$_POST = array( 'application_id' => '9', 'kind' => 'other', 'reason' => 'x' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamPaymentQueue() )->success );
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxResolveExamPayment() )->success );
	}

	public function test_office_cannot_create_or_publish_event(): void {
		// Офис видит очередь оплат, но экшены проведения требуют `ManageExams`, которого у него нет.
		$this->only( 'resolve_lms_exam_payments' );
		$events = $this->createMock( ExamEventService::class );
		$events->expects( self::never() )->method( 'createDraft' );
		$events->expects( self::never() )->method( 'publish' );
		$cb     = new ExamEventCallbacks( $this->createMock( ExamPlanService::class ), $events, $this->createMock( \Inc\Services\Exam\ExamLaunchChecklist::class ), $this->createMock( \Inc\Repositories\WPDBRepositories\ExamEventRepository::class ) );
		$_POST  = array( 'event_id' => '3', 'subject_key' => 'inf_ege', 'title' => 'x' );

		self::assertFalse( fs_test_capture_json( fn() => $cb->ajaxSaveExamEvent() )->success );
		self::assertFalse( fs_test_capture_json( fn() => $cb->ajaxPublishExamEvent() )->success );
	}

	public function test_resolve_passes_form_to_service_and_returns_refreshed_queue(): void {
		$this->only( 'resolve_lms_exam_payments' );
		$_POST = array( 'application_id' => '9', 'kind' => 'transferred', 'session_id' => '8', 'reason' => 'Мест не было', 'amount' => '' );
		$this->applications->expects( self::once() )->method( 'resolve' )->with( self::anything(), 9, ManualResolutionKind::Transferred, 8, 'Мест не было', null );
		$this->queue->method( 'list' )->willReturn( array( 'items' => array( array( 'application_id' => 10 ) ) ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxResolveExamPayment() );

		self::assertTrue( $r->success );
		self::assertSame( 10, $r->payload['items'][0]['application_id'] );
	}

	public function test_resolve_rejects_unknown_kind(): void {
		$this->only( 'resolve_lms_exam_payments' );
		$_POST = array( 'application_id' => '9', 'kind' => 'pending', 'reason' => 'x' );
		$this->applications->expects( self::never() )->method( 'resolve' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxResolveExamPayment() )->success );
	}

	public function test_service_refusal_is_returned_with_code(): void {
		$this->only( 'resolve_lms_exam_payments' );
		$_POST = array( 'application_id' => '9', 'kind' => 'other', 'reason' => 'x' );
		$this->applications->method( 'resolve' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Заявка не найдена.' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxResolveExamPayment() );

		self::assertFalse( $r->success );
		self::assertSame( ErrorCode::ExamAccess->value, $r->payload['code'] );
	}
}
