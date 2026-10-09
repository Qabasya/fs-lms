<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\GuestApplicationCallbacks;
use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestApplicationService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Exam\GuestSignupViewService;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\GuestOrderStatusService;
use Inc\Services\Exam\Payment\WooExamAdapter;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Security\FormGuardService;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Страница приглашения (ключ → кука → редирект без ключа, 404, лимит неудач) и «Перейти к оплате» (бронь, корзина, компенсация).
 */
#[AllowMockObjectsWithoutExpectations]
class GuestApplicationCallbacksTest extends TestCase {

	private ExamAccessTokenService&MockObject $tokens;
	private GuestSessionService&MockObject $sessions;
	private ExamSourceRepository&MockObject $sources;
	private RateLimitService&MockObject $rate;
	private GuestApplicationService&MockObject $applications;
	private WooExamAdapter&MockObject $adapter;
	private ExamHoldService&MockObject $holds;
	private FormGuardService&MockObject $guard;
	private WooGateway&MockObject $woo;
	private \Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository&MockObject $appRepo;
	private GuestApplicationCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$_GET    = array();
		$_COOKIE = array();
		$_POST   = array();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

		$this->tokens       = $this->createMock( ExamAccessTokenService::class );
		$this->sessions     = $this->createMock( GuestSessionService::class );
		$this->sources      = $this->createMock( ExamSourceRepository::class );
		$this->rate         = $this->createMock( RateLimitService::class );
		$this->applications = $this->createMock( GuestApplicationService::class );
		$this->adapter      = $this->createMock( WooExamAdapter::class );
		$this->holds        = $this->createMock( ExamHoldService::class );
		$this->guard        = $this->createMock( FormGuardService::class );
		$this->woo          = $this->createMock( WooGateway::class );
		$this->appRepo      = $this->createMock( \Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository::class );
		$time               = $this->createStub( ExamTime::class );
		$time->method( 'toLocal' )->willReturnArgument( 0 );
		$time->method( 'secondsUntil' )->willReturn( 1200 );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );

		$this->guard->method( 'honeypotField' )->willReturn( 'fs_company' );
		$this->guard->method( 'isHuman' )->willReturnCallback( static fn ( string $hp ): bool => '' === $hp );
		$this->woo->method( 'cartUrl' )->willReturn( 'https://example.test/cart/' );

		$this->cb = new GuestApplicationCallbacks(
			$this->tokens, $this->sessions, $this->sources, $this->rate, $this->createMock( GuestSignupViewService::class ),
			$this->applications, $this->adapter, $this->holds, $this->guard, $this->woo, $time,
			$this->createMock( GuestOrderStatusService::class ), $this->createMock( ExamPaymentReconciler::class ), $this->appRepo
		);
	}

	protected function tearDown(): void {
		$_GET = $_COOKIE = $_POST = array();
		parent::tearDown();
	}

	private function source( array $override = array() ): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array_merge( array(
			'id' => '14', 'event_id' => '3', 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5', 'grade' => '11', 'teacher_name' => 'Т', 'label' => 'l',
			'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		), $override ) );
	}

	private function token(): ExamAccessTokenDTO {
		return ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => 'invitation', 'target_id' => 14, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 2, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	private function givenValidCookie(): void {
		$_COOKIE[ GuestSessionService::COOKIE_INVITATION ] = str_repeat( 'b', 64 );
		$this->sessions->method( 'resolveInvitation' )->willReturn( 14 );
		$this->sources->method( 'find' )->willReturn( $this->source() );
	}

	// ── Страница приглашения ──────────────────────────────────────────────────────────────────────

	public function test_valid_key_sets_cookie_and_redirects_without_key(): void {
		$_GET['k'] = str_repeat( 'c', 64 );
		$this->tokens->method( 'exchange' )->with( ExamTokenPurpose::Invitation, str_repeat( 'c', 64 ) )->willReturn( $this->token() );
		$this->sources->method( 'find' )->willReturn( $this->source() );
		$this->sessions->expects( self::once() )->method( 'openInvitation' )->with( 14, 2 )->willReturn( 'COOKIEVALUE' );

		$outcome = $this->cb->handleInvitationPage();

		self::assertSame( GuestPageOutcome::REDIRECT, $outcome->kind );
		self::assertStringNotContainsString( 'k=', $outcome->url );
		self::assertSame( array( GuestSessionService::COOKIE_INVITATION => 'COOKIEVALUE' ), $outcome->cookies );
	}

	public function test_invalid_key_is_plain_404_and_counts_failure(): void {
		$_GET['k'] = str_repeat( 'd', 64 );
		$this->tokens->method( 'exchange' )->willReturn( null );
		$this->rate->expects( self::once() )->method( 'registerInvitationFailure' )->with( '10.0.0.7' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleInvitationPage()->kind );
	}

	public function test_no_key_no_cookie_is_404(): void {
		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleInvitationPage()->kind );
	}

	public function test_valid_cookie_renders_page(): void {
		$this->givenValidCookie();

		self::assertSame( GuestPageOutcome::RENDER, $this->cb->handleInvitationPage()->kind );
	}

	public function test_successful_openings_are_not_counted(): void {
		$_GET['k'] = str_repeat( 'c', 64 );
		$this->tokens->method( 'exchange' )->willReturn( $this->token() );
		$this->sources->method( 'find' )->willReturn( $this->source() );
		$this->sessions->method( 'openInvitation' )->willReturn( 'X' );
		$this->rate->expects( self::never() )->method( 'registerInvitationFailure' );

		$this->cb->handleInvitationPage();
	}

	public function test_locked_ip_gets_404_even_with_valid_key(): void {
		$_GET['k'] = str_repeat( 'c', 64 );
		$this->rate->method( 'isInvitationLocked' )->willReturn( true );
		$this->tokens->expects( self::never() )->method( 'exchange' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleInvitationPage()->kind );
	}

	public function test_revoked_source_with_valid_key_is_404(): void {
		$_GET['k'] = str_repeat( 'c', 64 );
		$this->tokens->method( 'exchange' )->willReturn( $this->token() );
		$this->sources->method( 'find' )->willReturn( $this->source( array( 'key_revoked_at' => '2026-03-02 00:00:00' ) ) );
		$this->sessions->expects( self::never() )->method( 'openInvitation' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleInvitationPage()->kind );
	}

	// ── «Перейти к оплате» ────────────────────────────────────────────────────────────────────────

	private function post( array $override = array() ): void {
		$_POST = array_merge( array(
			'last_name' => 'Иванов', 'first_name' => 'Пётр', 'phone' => '+7 (900) 111-22-33', 'session_id' => '7', 'consents' => array( 'pd_processing' ),
			'request_key' => 'req-key-12345678', 'form_token' => 'tok', 'fs_company' => '',
			// Попытка подмены: школа, класс, цена из запроса не читаются.
			'school_name' => 'Чужая', 'grade' => '9', 'price' => '1',
		), $override );
	}

	private function application( bool $held = true, string $state = 'awaiting_payment' ): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => $state, 'is_held' => $held ? '1' : '0',
			'hold_expires_at' => '2026-03-10 07:20:00', 'request_key' => 'req-key-12345678', 'version' => '1', 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		) );
	}

	public function test_submit_without_invitation_cookie_is_denied(): void {
		$this->post();
		$this->applications->expects( self::never() )->method( 'apply' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertFalse( $r->success );
	}

	public function test_honeypot_filled_is_rejected_without_details(): void {
		$this->givenValidCookie();
		$this->post( array( 'fs_company' => 'spam' ) );
		$this->applications->expects( self::never() )->method( 'apply' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertFalse( $r->success );
		self::assertSame( 'Не удалось отправить форму. Обновите страницу.', $r->payload['message'] );
	}

	public function test_submit_ignores_school_and_grade_from_request(): void {
		$this->givenValidCookie();
		$this->post();
		$this->applications->expects( self::once() )->method( 'apply' )->with(
			self::callback( static fn ( ExamSourceDTO $s ): bool => 'Школа 5' === $s->schoolName && 11 === $s->grade ),
			self::callback( static fn ( array $form ): bool => ! array_key_exists( 'school_name', $form ) && ! array_key_exists( 'grade', $form ) && ! array_key_exists( 'price', $form ) ),
			self::anything(),
			'req-key-12345678'
		)->willReturn( $this->application() );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() )->success );
	}

	public function test_response_has_server_expiry_and_cart_url(): void {
		$this->givenValidCookie();
		$this->post();
		$this->applications->method( 'apply' )->willReturn( $this->application() );
		$this->adapter->expects( self::once() )->method( 'addToCart' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertTrue( $r->success );
		self::assertSame( 'https://example.test/cart/', $r->payload['redirect'] );
		self::assertSame( '2026-03-10 07:20:00', $r->payload['hold_expires_at'] );
		self::assertSame( 1200, $r->payload['seconds_left'] );
	}

	public function test_cart_failure_releases_hold_once(): void {
		$this->givenValidCookie();
		$this->post();
		$this->applications->method( 'apply' )->willReturn( $this->application() );
		$this->adapter->method( 'addToCart' )->willThrowException( new \RuntimeException( 'woo down' ) );
		$this->holds->expects( self::once() )->method( 'release' )->with( 9, GuestApplicationState::Failed );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertFalse( $r->success );
		self::assertSame( 'Не удалось перейти к оплате. Попробуйте ещё раз.', $r->payload['message'] );
		self::assertTrue( $r->payload['new_request_key'], 'После компенсации клиент берёт новый request_key.' );
	}

	public function test_repeat_with_same_request_key_returns_same_hold(): void {
		$this->givenValidCookie();
		$this->post();
		$this->applications->expects( self::exactly( 2 ) )->method( 'apply' )->willReturn( $this->application() );

		$a = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );
		$b = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertTrue( $a->success );
		self::assertSame( $a->payload['hold_expires_at'], $b->payload['hold_expires_at'], 'Срок не продлевается повтором.' );
	}

	public function test_expired_hold_on_repeat_asks_for_new_selection(): void {
		$this->givenValidCookie();
		$this->post();
		$this->applications->method( 'apply' )->willReturn( $this->application( false, 'expired_unpaid' ) );
		$this->adapter->expects( self::never() )->method( 'addToCart' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertFalse( $r->success );
		self::assertTrue( $r->payload['new_request_key'] );
	}

	public function test_field_error_goes_to_its_field_and_limit_is_plain(): void {
		$this->givenValidCookie();
		$this->post();
		$this->applications->method( 'apply' )->willThrowException( new \Inc\Shared\GuestFormException( ErrorCode::ExamConflict, 'Укажите телефон: 11 цифр.', 'phone' ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() );

		self::assertSame( 'phone', $r->payload['field'] );
	}

	public function test_bad_request_key_is_rejected(): void {
		$this->givenValidCookie();
		$this->post( array( 'request_key' => 'x' ) );
		$this->applications->expects( self::never() )->method( 'apply' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitExamGuestApplication() )->success );
	}

	// ── Ссылка на оплату (11a.7.5) ────────────────────────────────────────────────────────────────

	private function payToken(): ExamAccessTokenDTO {
		return ExamAccessTokenDTO::fromArray( array(
			'id' => 2, 'purpose' => 'payment', 'target_id' => 9, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 1, 'issuer_user_id' => 10, 'created_at' => '2026-03-10 07:00:00',
		) );
	}

	public function test_pay_link_adds_item_and_redirects_to_cart(): void {
		$_GET['pay'] = str_repeat( 'e', 64 );
		$this->tokens->method( 'exchange' )->with( ExamTokenPurpose::Payment, str_repeat( 'e', 64 ) )->willReturn( $this->payToken() );
		$this->appRepo->method( 'find' )->willReturn( $this->application() );
		$this->adapter->expects( self::once() )->method( 'addToCart' );

		$outcome = $this->cb->handleInvitationPage();

		self::assertSame( GuestPageOutcome::REDIRECT, $outcome->kind );
		self::assertSame( 'https://example.test/cart/', $outcome->url );
	}

	public function test_pay_link_for_expired_hold_is_404(): void {
		$_GET['pay'] = str_repeat( 'e', 64 );
		$this->tokens->method( 'exchange' )->willReturn( $this->payToken() );
		$this->appRepo->method( 'find' )->willReturn( $this->application( false, 'expired_unpaid' ) );
		$this->adapter->expects( self::never() )->method( 'addToCart' );
		$this->rate->expects( self::once() )->method( 'registerInvitationFailure' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleInvitationPage()->kind );
	}

	public function test_pay_link_token_of_other_purpose_is_404(): void {
		$_GET['pay'] = str_repeat( 'e', 64 );
		// exchange(Payment, …) ключ другого назначения не принимает и отвечает null.
		$this->tokens->method( 'exchange' )->willReturn( null );
		$this->adapter->expects( self::never() )->method( 'addToCart' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleInvitationPage()->kind );
	}
}
