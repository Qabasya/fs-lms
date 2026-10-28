<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamConductCallbacks;
use Inc\DTO\Exam\RegistrationResultDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamApprovalService;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamConductService;
use Inc\Services\Exam\ExamConductViewService;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Export\ExportService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AJAX проведения экзамена: права и nonce, делегирование сервисам, ответ — обновлённая доска.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamConductCallbacksTest extends TestCase {

	private ExamConductService&MockObject $conduct;
	private ExamRegistrationService&MockObject $registration;
	private ExamAttemptService&MockObject $attempts;
	private ExamApprovalService&MockObject $approval;
	private ExportService&MockObject $exports;
	private \Inc\Services\Exam\GuestOnSiteService&MockObject $onSite;
	private \Inc\Services\Exam\ExamRetentionService&MockObject $retention;
	private ExamConductViewService&MockObject $board;
	private ExamConductCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 10;

		$this->board        = $this->createMock( ExamConductViewService::class );
		$this->conduct      = $this->createMock( ExamConductService::class );
		$this->registration = $this->createMock( ExamRegistrationService::class );
		$this->attempts     = $this->createMock( ExamAttemptService::class );
		$this->approval     = $this->createMock( ExamApprovalService::class );
		$this->exports      = $this->createMock( ExportService::class );
		$this->onSite       = $this->createMock( \Inc\Services\Exam\GuestOnSiteService::class );
		$this->retention    = $this->createMock( \Inc\Services\Exam\ExamRetentionService::class );
		$this->board->method( 'sessionBoard' )->willReturn( array( 'session' => array( 'id' => 7 ), 'rows' => array() ) );
		$this->cb = new ExamConductCallbacks( $this->conduct, $this->registration, $this->attempts, $this->approval, $this->exports, $this->onSite, $this->retention, $this->createMock( \Inc\Services\Exam\ExamGuestDeletionService::class ), $this->board );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'] );
		parent::tearDown();
	}

	public function test_requires_manage_exams(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST                   = array( 'session_id' => '7' );
		$this->board->expects( self::never() )->method( 'sessionBoard' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamConduct() )->success );
	}

	public function test_default_session_is_running_or_next(): void {
		$_POST = array();
		$this->board->expects( self::once() )->method( 'defaultSessionId' )->with( 10 )->willReturn( 7 );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamConduct() );

		self::assertTrue( $r->success );
		self::assertSame( 7, $r->payload['board']['session']['id'] );
	}

	/** Коллбеки с чистым сервисом доски: у общего мока `sessionBoard` уже настроен в setUp(). */
	private function callbacksWithBoard( ExamConductViewService $board ): ExamConductCallbacks {
		return new ExamConductCallbacks( $this->conduct, $this->registration, $this->attempts, $this->approval, $this->exports, $this->onSite, $this->retention, $this->createMock( \Inc\Services\Exam\ExamGuestDeletionService::class ), $board );
	}

	public function test_subject_switch_keeps_the_session_date_when_the_new_subject_has_one(): void {
		$_POST = array( 'subject_key' => 'inf_oge', 'date' => '2026-03-12', 'all' => '1' );
		$board = $this->createMock( ExamConductViewService::class );
		$board->expects( self::once() )->method( 'subjectSessionOnDate' )->with( 10, 'inf_oge', '2026-03-12' )->willReturn( 12 );
		$board->expects( self::never() )->method( 'defaultSessionId' );
		$board->expects( self::once() )->method( 'sessionBoard' )->with( 10, 12, false )->willReturn( array( 'session' => array( 'id' => 12 ), 'all' => false, 'rows' => array() ) );
		$cb = $this->callbacksWithBoard( $board );

		$r = fs_test_capture_json( fn() => $cb->ajaxGetExamConduct() );

		self::assertSame( 12, $r->payload['board']['session']['id'] );
		self::assertFalse( $r->payload['board']['all'] );
	}

	public function test_subject_switch_falls_back_to_all_sessions_without_a_session_that_day(): void {
		$_POST = array( 'subject_key' => 'inf_oge', 'date' => '2026-03-12' );
		$board = $this->createMock( ExamConductViewService::class );
		$board->method( 'subjectSessionOnDate' )->willReturn( null );
		$board->method( 'defaultSessionId' )->with( 10, 'inf_oge' )->willReturn( 5 );
		$board->expects( self::once() )->method( 'sessionBoard' )->with( 10, 5, true )->willReturn( array( 'session' => array( 'id' => 5 ), 'all' => true, 'rows' => array() ) );
		$cb = $this->callbacksWithBoard( $board );

		$r = fs_test_capture_json( fn() => $cb->ajaxGetExamConduct() );

		self::assertTrue( $r->payload['board']['all'] );
	}

	public function test_date_without_subject_is_ignored(): void {
		$_POST = array( 'date' => '2026-03-12' );
		$this->board->expects( self::never() )->method( 'subjectSessionOnDate' );
		$this->board->method( 'defaultSessionId' )->willReturn( 7 );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamConduct() )->success );
	}

	public function test_set_seat_passes_the_seat_and_returns_the_board(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'seat_no' => '4' );
		$this->conduct->expects( self::once() )->method( 'setSeat' )->with( 10, 5, 4 );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetExamSeat() )->success );
	}

	public function test_set_seat_with_zero_clears_it(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'seat_no' => '0' );
		$this->conduct->expects( self::once() )->method( 'setSeat' )->with( 10, 5, null );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetExamSeat() )->success );
	}

	public function test_set_seat_requires_manage_exams(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST                   = array( 'session_id' => '7', 'registration_id' => '5', 'seat_no' => '4' );
		$this->conduct->expects( self::never() )->method( 'setSeat' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSetExamSeat() )->success );
	}

	public function test_link_passed_mark_is_set_by_default_and_cleared_with_passed_zero(): void {
		$set = array( 'session_id' => '7', 'participation_id' => '4', 'purpose' => 'result' );
		$this->conduct->expects( self::exactly( 2 ) )->method( 'markLinkPassed' )->willReturnCallback( function ( int $actor, int $participation, $purpose, bool $passed ) use ( &$calls ): void {
			$calls[] = $passed;
		} );

		$_POST = $set;
		fs_test_capture_json( fn() => $this->cb->ajaxMarkExamLinkPassed() );
		$_POST = $set + array( 'passed' => '0' );
		fs_test_capture_json( fn() => $this->cb->ajaxMarkExamLinkPassed() );

		self::assertSame( array( true, false ), $calls );
	}

	public function test_no_sessions_returns_null_board(): void {
		$_POST = array();
		$this->board->method( 'defaultSessionId' )->willReturn( null );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamConduct() );

		self::assertTrue( $r->success );
		self::assertNull( $r->payload['board'] );
	}

	public function test_cancel_requires_reason(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'reason' => '' );
		$this->registration->method( 'cancelByStaff' )->willThrowException( new \InvalidArgumentException( 'Укажите причину отмены.' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistrationByStaff() );

		self::assertFalse( $r->success );
	}

	public function test_cancel_delegates_and_returns_board(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'reason' => 'Болезнь' );
		$this->registration->expects( self::once() )->method( 'cancelByStaff' )->with( 10, 5, 'Болезнь' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistrationByStaff() );

		self::assertTrue( $r->success );
		self::assertSame( 7, $r->payload['board']['session']['id'] );
	}

	public function test_transfer_delegates_with_reason(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'target_session_id' => '8', 'reason' => 'Другой день' );
		$this->registration->expects( self::once() )->method( 'transferByStaff' )->with( 10, 5, 8, 'Другой день' )
			->willReturn( $this->createMock( RegistrationResultDTO::class ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxTransferExamRegistration() )->success );
	}

	public function test_extend_delegates_minutes_and_reason(): void {
		$_POST = array( 'session_id' => '7', 'attempt_id' => '11', 'minutes' => '10', 'reason' => 'Сбой' );
		$this->attempts->expects( self::once() )->method( 'extend' )->with( 10, 11, 10, 'Сбой' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxExtendExamAttempt() )->success );
	}

	public function test_extend_rejects_minutes_out_of_range(): void {
		$this->attempts->expects( self::never() )->method( 'extend' );

		foreach ( array( '0', '121', 'abc' ) as $minutes ) {
			$_POST = array( 'session_id' => '7', 'attempt_id' => '11', 'minutes' => $minutes, 'reason' => 'Сбой' );
			self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxExtendExamAttempt() )->success, $minutes );
		}
	}

	public function test_mark_arrival_passes_flag(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'arrived' => '1' );
		$this->conduct->expects( self::once() )->method( 'markArrival' )->with( 10, 5, true );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxMarkExamArrival() )->success );
	}

	public function test_confirm_guest_passes_flag(): void {
		$_POST = array( 'session_id' => '7', 'participation_id' => '4', 'confirmed' => '1' );
		$this->conduct->expects( self::once() )->method( 'confirmGuest' )->with( 10, 4, true );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxConfirmExamGuest() )->success );
	}

	public function test_notify_guest_passes_flag(): void {
		$_POST = array( 'participation_id' => '4', 'notified' => '1' );
		$this->conduct->expects( self::once() )->method( 'notifyGuest' )->with( 10, 4, true );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxNotifyExamGuest() )->success );
	}

	public function test_actions_denied_for_foreign_event(): void {
		$_POST = array( 'session_id' => '7', 'registration_id' => '5', 'reason' => 'x' );
		$this->registration->method( 'cancelByStaff' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Запись не найдена.' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistrationByStaff() );

		self::assertFalse( $r->success );
	}

	public function test_approve_many_reads_items_array(): void {
		$_POST = array( 'session_id' => '7', 'items' => array(
			array( 'attempt_id' => '11', 'result_version' => '2' ),
			array( 'attempt_id' => '12', 'result_version' => '0' ),
			'junk',
		) );
		$this->approval->expects( self::once() )->method( 'approveMany' )->with(
			10,
			array( array( 'attempt_id' => 11, 'result_version' => 2 ), array( 'attempt_id' => 12, 'result_version' => 0 ) )
		)->willReturn( array( 'approved' => 2, 'skipped' => array() ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxApproveExamAttempts() )->success );
	}

	public function test_approve_many_returns_counts_and_board(): void {
		$_POST = array( 'session_id' => '7', 'items' => array( array( 'attempt_id' => '11', 'result_version' => '2' ) ) );
		$this->approval->method( 'approveMany' )->willReturn( array(
			'approved' => 1,
			'skipped'  => array( array( 'attempt_id' => 12, 'reason' => 'pending_review', 'reason_label' => 'Проверка не завершена' ) ),
		) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxApproveExamAttempts() );

		self::assertTrue( $r->success );
		self::assertSame( 1, $r->payload['approved'] );
		self::assertCount( 1, $r->payload['skipped'] );
		self::assertSame( 7, $r->payload['board']['session']['id'] );
	}

	public function test_approve_many_without_items_is_refused(): void {
		$_POST = array( 'session_id' => '7' );
		$this->approval->expects( self::never() )->method( 'approveMany' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxApproveExamAttempts() )->success );
	}

	public function test_correct_result_reads_changes_and_delegates(): void {
		$_POST = array(
			'attempt_id' => '11', 'reason' => 'Пересчёт', 'result_version' => '4',
			'changes' => array( array( 'task_id' => '7', 'score' => '1,5', 'feedback' => 'ок' ), 'junk' ),
		);
		$this->approval->expects( self::once() )->method( 'correct' )->with(
			10,
			11,
			array( array( 'task_id' => 7, 'score' => 1.5, 'feedback' => 'ок' ) ),
			'Пересчёт',
			4
		)->willReturn( \Inc\DTO\Assessment\AttemptDTO::fromArray( array(
			'id' => 11, 'assessment_id' => 1, 'attempt_number' => 1, 'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 11:00:00', 'total_score' => 21,
		) ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCorrectExamResult() );

		self::assertTrue( $r->success );
		self::assertSame( 21.0, $r->payload['total_score'] );
	}

	public function test_correct_result_failure_is_reported_with_code(): void {
		$_POST = array( 'attempt_id' => '11', 'reason' => 'x', 'result_version' => '1', 'changes' => array( array( 'task_id' => '7', 'score' => '1' ) ) );
		$this->approval->method( 'correct' )->willThrowException( new CodedException( ErrorCode::ExamStale, 'Работу уже изменил другой проверяющий.' ) );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCorrectExamResult() )->success );
	}

	/* ── 8.9 Выгрузка и печать ─────────────────────────────────────────────── */

	public function test_export_requires_both_pii_caps(): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'manage_lms_platform' === $cap;
		$_POST                            = array( 'session_id' => '7' );
		$this->exports->expects( self::never() )->method( 'run' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxExportExamParticipants() )->success );
		unset( $GLOBALS['_fs_test_can_callback'] );
	}

	public function test_export_denied_for_teacher_without_export_pii(): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'export_pii' === $cap || 'manage_lms_exams' === $cap;
		$_POST                            = array( 'session_id' => '7' );
		$this->exports->expects( self::never() )->method( 'run' );
		$this->board->expects( self::never() )->method( 'exportRows' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetExamPrintList() )->success );
		unset( $GLOBALS['_fs_test_can_callback'] );
	}

	public function test_export_denied_for_foreign_event(): void {
		$_POST = array( 'session_id' => '7' );
		$this->exports->expects( self::once() )->method( 'run' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Сеанс не найден.' ) );
		$this->board->expects( self::never() )->method( 'exportRows' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxExportExamParticipants() )->success );
	}

	public function test_export_returns_one_time_url_for_selected_participations(): void {
		$_POST = array( 'participation_ids' => array( '5', '6', '0' ) );
		$this->board->expects( self::never() )->method( 'exportRows' );
		$this->exports->expects( self::once() )->method( 'run' )->with(
			\Inc\Enums\Export\ExportTarget::ExamParticipants,
			array( 'participation_ids' => array( 5, 6 ), 'ids' => array( 5, 6 ), 'actor_user_id' => 10 ),
			'single'
		)->willReturn( 'https://example.test/lms/export/token' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxExportExamParticipants() );

		self::assertTrue( $r->success );
		self::assertSame( 'https://example.test/lms/export/token', $r->payload['url'] );
	}

	public function test_print_list_has_no_contacts_or_links(): void {
		$_POST = array( 'session_id' => '7' );
		$this->board->method( 'exportRows' )->willReturn( array(
			array( 'name' => 'Иванов Пётр', 'source' => 'Школа 5', 'session' => '2026-03-10 10:00', 'status' => 'Работа сдана', 'primary' => '10 / 20', 'secondary' => '' ),
		) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetExamPrintList() );

		self::assertSame( array( array( 'name' => 'Иванов Пётр', 'source' => 'Школа 5', 'session' => '2026-03-10 10:00' ) ), $r->payload['rows'] );
	}

	public function test_export_without_selection_is_refused(): void {
		$_POST = array();
		$this->exports->expects( self::never() )->method( 'run' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxExportExamParticipants() )->success );
	}

	// ── 11a.7 Добавить гостя на месте ─────────────────────────────────────────────────────────────

	public function test_add_guest_requires_manage_exam_guests(): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'manage_lms_exam_guests' !== $cap;
		$_POST                            = array( 'session_id' => '7', 'source_id' => '3' );
		$this->onSite->expects( self::never() )->method( 'add' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAddExamGuestOnSite() )->success );
		unset( $GLOBALS['_fs_test_can_callback'] );
	}

	public function test_add_guest_returns_duplicate_candidates_before_creating(): void {
		$_POST = array( 'session_id' => '7', 'source_id' => '3', 'last_name' => 'Иванов', 'first_name' => 'Пётр', 'phone' => '9001112233', 'request_key' => 'rk-12345678', 'consents' => array( 'pd_processing' ) );
		$this->onSite->method( 'add' )->willReturn( array( 'status' => 'needs_confirmation', 'candidates' => array( array( 'name' => 'Иванов Пётр', 'participation_id' => 5, 'match' => 'both' ) ) ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxAddExamGuestOnSite() );

		self::assertTrue( $r->success );
		self::assertSame( 'needs_confirmation', $r->payload['status'] );
	}

	public function test_confirmed_flag_is_passed_to_service(): void {
		$_POST = array( 'session_id' => '7', 'source_id' => '3', 'request_key' => 'rk-12345678', 'confirmed' => '1' );
		$this->onSite->expects( self::once() )->method( 'add' )->with( 10, 7, 3, self::anything(), self::anything(), 'rk-12345678', true )->willReturn( array( 'status' => 'created' ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxAddExamGuestOnSite() )->success );
	}

	public function test_on_site_application_preserves_request_context(): void {
		$server = $_SERVER;
		try {
			$_SERVER['REMOTE_ADDR']     = '10.0.0.15';
			$_SERVER['HTTP_USER_AGENT'] = 'Exam staff browser';
			$_POST = array( 'session_id' => '7', 'source_id' => '3', 'request_key' => 'rk-12345678' );
			$this->onSite->expects( self::once() )->method( 'add' )->with(
				10, 7, 3, self::anything(),
				self::callback( static fn ( \Inc\DTO\RequestContextDTO $ctx ): bool => '10.0.0.15' === $ctx->ip && 'Exam staff browser' === $ctx->userAgent && 10 === $ctx->actorUserId ),
				'rk-12345678', false,
			)->willReturn( array( 'status' => 'created' ) );

			self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxAddExamGuestOnSite() )->success );
		} finally {
			$_SERVER = $server;
		}
	}

	public function test_add_guest_denied_after_planned_end(): void {
		$_POST = array( 'session_id' => '7', 'source_id' => '3' );
		$this->onSite->method( 'add' )->willThrowException( new CodedException( ErrorCode::ExamClosed, 'Сеанс уже закончился: добавить участника нельзя.' ) );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAddExamGuestOnSite() )->success );
	}

	public function test_add_guest_returns_pay_url_once(): void {
		$_POST = array( 'session_id' => '7', 'source_id' => '3' );
		$this->onSite->method( 'add' )->willReturn( array( 'status' => 'created', 'application_id' => 9, 'pay_url' => 'https://x.test/exam-signup/?pay=abc', 'hold_expires_at' => '2026-03-10 10:20:00', 'seconds_left' => 1200 ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxAddExamGuestOnSite() );

		self::assertSame( 'https://x.test/exam-signup/?pay=abc', $r->payload['pay_url'] );
	}

	public function test_pay_link_reissue_is_guarded_by_guest_cap(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST                   = array( 'application_id' => '9' );
		$this->onSite->expects( self::never() )->method( 'reissuePayLink' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxIssueExamGuestPayLink() )->success );
	}

	// ── 13.3.5 удаление данных гостя ──────────────────────────────────────────────────────────────

	public function test_manual_anonymize_requires_both_caps_and_reason(): void {
		$_POST = array( 'participant_id' => '9', 'session_id' => '7', 'reason' => 'Запрос представителя' );
		$this->retention->expects( self::never() )->method( 'anonymizeByStaff' );

		// Только право на гостей, без права на раздел: отказ.
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'manage_lms_exam_guests' === $cap;
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAnonymizeExamGuest() )->success );

		// Только право на раздел, без права на гостей: отказ.
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'manage_lms_exam_guests' !== $cap;
		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAnonymizeExamGuest() )->success );
		unset( $GLOBALS['_fs_test_can_callback'] );
	}

	public function test_manual_anonymize_passes_reason_to_service(): void {
		$_POST = array( 'participant_id' => '9', 'session_id' => '7', 'reason' => 'Запрос представителя' );
		$this->retention->expects( self::once() )->method( 'anonymizeByStaff' )->with( self::anything(), 9, 'Запрос представителя' );
		$this->board->method( 'sessionBoard' )->willReturn( array( 'rows' => array() ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxAnonymizeExamGuest() )->success );
	}
}
