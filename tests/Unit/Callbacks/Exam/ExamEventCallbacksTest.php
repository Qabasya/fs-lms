<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamEventCallbacks;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamEventService;
use Inc\Services\Exam\ExamPlanService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * AJAX планирования экзаменов: права и nonce, передача ввода сервису без пересчёта времени, отказы сервиса с кодом.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamEventCallbacksTest extends TestCase {

	use ExamFixtures;

	private ExamPlanService&MockObject $plans;
	private ExamEventService&MockObject $events;
	private ExamEventCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 10;

		$this->plans  = $this->createMock( ExamPlanService::class );
		$this->events = $this->createMock( ExamEventService::class );
		$this->cb     = new ExamEventCallbacks( $this->plans, $this->events );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		parent::tearDown();
	}

	public function test_get_plan_requires_manage_exams(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST                   = array( 'subject_key' => 'inf_ege' );
		$this->plans->expects( self::never() )->method( 'build' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamPlan() );

		self::assertFalse( $r->success );
	}

	public function test_stale_nonce_is_refused_before_any_work(): void {
		$GLOBALS['_fs_test_nonce_ok'] = false;
		$_POST                        = array( 'subject_key' => 'inf_ege' );
		$this->plans->expects( self::never() )->method( 'build' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamPlan() );

		self::assertFalse( $r->success );
		self::assertSame( 'E-SESSION', $r->payload['code'] );
	}

	public function test_get_plan_passes_subject_and_event_of_current_user(): void {
		$this->plans->expects( self::once() )->method( 'build' )->with( 10, 'inf_ege', 3 )->willReturn( array( 'events' => array(), 'event' => null, 'variants' => array(), 'rooms' => array() ) );
		$_POST = array( 'subject_key' => 'inf_ege', 'event_id' => '3' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamPlan() );

		self::assertTrue( $r->success );
		self::assertArrayHasKey( 'rooms', $r->payload );
	}

	public function test_get_plan_denied_for_foreign_subject_returns_coded_error(): void {
		$this->plans->method( 'build' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Нет доступа к экзаменам этого предмета.' ) );
		$_POST = array( 'subject_key' => 'math' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamPlan() );

		self::assertFalse( $r->success );
		self::assertSame( 'X-ACCESS', $r->payload['code'] );
		self::assertArrayHasKey( 'ref', $r->payload, 'Номер инцидента для журнала и скриншота.' );
	}

	public function test_save_session_passes_local_date_and_time_to_service(): void {
		$session = $this->examSession();
		$this->events->expects( self::once() )->method( 'saveSession' )->with(
			10,
			3,
			array( 'date' => '2026-03-10', 'time' => '10:00', 'assessment_id' => 500, 'room_id' => 2 ),
			null,
			null
		)->willReturn( $session );
		$this->plans->method( 'sessionPayload' )->willReturn( array( 'id' => 7 ) );
		$_POST = array( 'event_id' => '3', 'date' => '2026-03-10', 'time' => '10:00', 'assessment_id' => '500', 'room_id' => '2' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveExamSession() );

		self::assertTrue( $r->success );
		self::assertSame( array( 'id' => 7 ), $r->payload['session'] );
	}

	public function test_save_session_edit_passes_session_and_version(): void {
		$this->events->expects( self::once() )->method( 'saveSession' )->with( 10, 3, self::anything(), 7, 4 )->willReturn( $this->examSession() );
		$this->plans->method( 'sessionPayload' )->willReturn( array() );
		$_POST = array( 'event_id' => '3', 'session_id' => '7', 'version' => '4', 'date' => '2026-03-10', 'time' => '10:00', 'assessment_id' => '500', 'room_id' => '2' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveExamSession() )->success );
	}

	public function test_save_session_returns_coded_error_from_service(): void {
		$this->events->method( 'saveSession' )->willThrowException( new CodedException( ErrorCode::ExamConflict, 'Кабинет занят в это время.' ) );
		$_POST = array( 'event_id' => '3', 'date' => '2026-03-10', 'time' => '10:00', 'assessment_id' => '500', 'room_id' => '2' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveExamSession() );

		self::assertFalse( $r->success );
		self::assertSame( 'X-CONFLICT', $r->payload['code'] );
		self::assertSame( 'Кабинет занят в это время.', $r->payload['message'] );
	}

	public function test_save_session_denied_without_event_scope(): void {
		$this->events->method( 'saveSession' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.' ) );
		$_POST = array( 'event_id' => '3', 'date' => '2026-03-10', 'time' => '10:00', 'assessment_id' => '500', 'room_id' => '2' );

		self::assertSame( 'X-ACCESS', fs_test_capture_json( fn() => $this->cb->ajaxSaveExamSession() )->payload['code'] );
	}

	public function test_save_session_requires_event_id(): void {
		$this->events->expects( self::never() )->method( 'saveSession' );
		$_POST = array( 'date' => '2026-03-10', 'time' => '10:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveExamSession() )->success );
	}

	public function test_delete_session_delegates_to_service(): void {
		$this->events->expects( self::once() )->method( 'deleteSession' )->with( 10, 7 );
		$_POST = array( 'session_id' => '7' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxDeleteExamSession() );

		self::assertTrue( $r->success );
		self::assertSame( 7, $r->payload['session_id'] );
	}

	public function test_save_event_creates_draft_for_own_subject(): void {
		$event = $this->examEvent();
		$this->events->expects( self::never() )->method( 'updateEvent' );
		$this->events->expects( self::once() )->method( 'createDraft' )->with( 10, self::callback( static fn ( array $in ): bool =>
			'inf_ege' === $in['subject_key'] && 'Пробный ЕГЭ' === $in['title'] && '2026-03-10' === $in['period_from'] && '2026-03-12' === $in['period_to']
			&& '2026-03-02 09:00' === $in['registration_opens_at'] && 500 === $in['default_assessment_id'] && true === $in['guest_registration_enabled']
		) )->willReturn( $event );
		$this->plans->method( 'eventPayload' )->willReturn( array( 'id' => 3 ) );
		$_POST = array(
			'subject_key' => 'inf_ege', 'title' => 'Пробный ЕГЭ', 'description' => "Строка 1\nСтрока 2", 'period_from' => '2026-03-10', 'period_to' => '2026-03-12',
			'registration_opens_at' => '2026-03-02 09:00', 'registration_closes_at' => '2026-03-09 18:00', 'default_assessment_id' => '500', 'guest_registration_enabled' => '1',
		);

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveExamEvent() );

		self::assertTrue( $r->success );
		self::assertSame( array( 'id' => 3 ), $r->payload['event'] );
	}

	public function test_save_event_with_id_updates_with_expected_version(): void {
		$this->events->expects( self::never() )->method( 'createDraft' );
		$this->events->expects( self::once() )->method( 'updateEvent' )->with( 10, 3, self::anything(), 6 )->willReturn( $this->examEvent() );
		$this->plans->method( 'eventPayload' )->willReturn( array() );
		$_POST = array( 'event_id' => '3', 'version' => '6', 'subject_key' => 'inf_ege', 'title' => 'Новое имя' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveExamEvent() )->success );
	}

	public function test_save_event_rejected_for_foreign_subject(): void {
		$this->events->method( 'createDraft' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.' ) );
		$_POST = array( 'subject_key' => 'math', 'title' => 'Чужое' );

		self::assertSame( 'X-ACCESS', fs_test_capture_json( fn() => $this->cb->ajaxSaveExamEvent() )->payload['code'] );
	}

	public function test_publish_passes_expected_version(): void {
		$this->events->expects( self::once() )->method( 'publish' )->with( 10, 3, 5 )->willReturn( $this->examEvent( array( 'status' => 'published' ) ) );
		$this->plans->method( 'eventPayload' )->willReturn( array( 'status' => 'published' ) );
		$_POST = array( 'event_id' => '3', 'version' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPublishExamEvent() );

		self::assertTrue( $r->success );
		self::assertSame( 'published', $r->payload['event']['status'] );
	}

	public function test_publish_stale_version_returns_x_stale(): void {
		$this->events->method( 'publish' )->willThrowException( new CodedException( ErrorCode::ExamStale, 'Данные изменились: обновите страницу и повторите.' ) );
		$_POST = array( 'event_id' => '3', 'version' => '1' );

		self::assertSame( 'X-STALE', fs_test_capture_json( fn() => $this->cb->ajaxPublishExamEvent() )->payload['code'] );
	}

	public function test_cancel_requires_reason(): void {
		$this->events->method( 'cancelEvent' )->willThrowException( new CodedException( ErrorCode::ExamConflict, 'Укажите причину отмены.' ) );
		$_POST = array( 'event_id' => '3', 'reason' => '', 'version' => '1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCancelExamEvent() );

		self::assertFalse( $r->success );
		self::assertSame( 'Укажите причину отмены.', $r->payload['message'] );
	}

	public function test_cancel_passes_reason_and_version(): void {
		$this->events->expects( self::once() )->method( 'cancelEvent' )->with( 10, 3, 'Нет мест', 2 );
		$_POST = array( 'event_id' => '3', 'reason' => 'Нет мест', 'version' => '2' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxCancelExamEvent() )->success );
	}

	public function test_plain_validation_error_is_returned_as_message(): void {
		$this->events->method( 'deleteSession' )->willThrowException( new \InvalidArgumentException( 'Неверный запрос.' ) );
		$_POST = array( 'session_id' => '7' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxDeleteExamSession() );

		self::assertFalse( $r->success );
	}
}
