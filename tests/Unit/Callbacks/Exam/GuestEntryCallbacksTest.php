<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\GuestEntryCallbacks;
use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestEntryViewService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Security\RateLimitService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Страница входа гостя: ключ → кука → редирект без ключа; любой отказ — одна и та же 404; гость не получает учётку WordPress.
 */
#[AllowMockObjectsWithoutExpectations]
class GuestEntryCallbacksTest extends TestCase {

	use ExamFixtures;

	private ExamAccessTokenService&MockObject $tokens;
	private GuestSessionService&MockObject $sessions;
	private ExamParticipationRepository&MockObject $participations;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamSessionRepository&MockObject $sessionRepo;
	private RateLimitService&MockObject $rate;
	private \Inc\Services\Exam\GuestResultViewService&MockObject $result;
	private \Inc\Services\Exam\GuestPageResponder&MockObject $responder;
	private GuestEntryCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		$_GET    = array();
		$_COOKIE = array();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

		$this->tokens         = $this->createMock( ExamAccessTokenService::class );
		$this->sessions       = $this->createMock( GuestSessionService::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->sessionRepo    = $this->createMock( ExamSessionRepository::class );
		$this->rate           = $this->createMock( RateLimitService::class );
		$this->result         = $this->createMock( \Inc\Services\Exam\GuestResultViewService::class );
		$this->responder      = $this->createMock( \Inc\Services\Exam\GuestPageResponder::class );
		$time                 = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );

		$this->cb = new GuestEntryCallbacks(
			$this->tokens, $this->sessions, $this->participations, $this->registrations, $this->sessionRepo, $this->rate,
			$this->createMock( GuestEntryViewService::class ), $time, $this->result, $this->responder,
			$this->createMock( \Inc\Services\Exam\GuestResultSidebarService::class )
		);
	}

	protected function tearDown(): void {
		$_GET = $_COOKIE = array();
		parent::tearDown();
	}

	private function token(): ExamAccessTokenDTO {
		return ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => 'entry', 'target_id' => 4, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 2, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	/** @param array<string, mixed> $over */
	private function participation( array $over = array() ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array_merge( array(
			'id' => '4', 'event_id' => '3', 'participant_id' => '9', 'audience' => 'guest', 'active_registration_id' => '8', 'current_attempt_id' => null,
			'source_id' => '14', 'consent_refs' => null, 'transfer_allowed' => '0', 'admitted_at' => '2026-03-10 06:30:00', 'admitted_by_user_id' => '10',
			'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		), $over ) );
	}

	private function givenValidKey(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->method( 'exchange' )->willReturn( $this->token() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->registrations->method( 'find' )->willReturn( ExamRegistrationDTO::fromArray( array(
			'id' => '8', 'participation_id' => '4', 'session_id' => '7', 'status' => 'confirmed', 'active_slot' => '1', 'request_key' => null, 'reason' => null,
			'actor_user_id' => null, 'arrived_at' => null, 'arrived_by_user_id' => null, 'created_at' => '2026-03-01 00:00:00', 'cancelled_at' => null, 'transferred_at' => null, 'missed_at' => null,
		) ) );
		$this->sessionRepo->method( 'find' )->willReturn( $this->examSession() );
	}

	public function test_valid_key_sets_cookie_and_redirects_without_key(): void {
		$this->givenValidKey();
		$this->tokens->expects( self::once() )->method( 'exchange' )->with( ExamTokenPurpose::Entry, str_repeat( 'a', 64 ) );
		$this->sessions->expects( self::once() )->method( 'openEntry' )->with( self::anything(), 8, '2026-03-10 10:55:00' )->willReturn( str_repeat( 'c', 64 ) );

		$outcome = $this->cb->handleEntryPage();

		self::assertSame( GuestPageOutcome::REDIRECT, $outcome->kind );
		self::assertStringNotContainsString( 'k=', $outcome->url );
		self::assertSame( array( GuestSessionService::COOKIE_ENTRY => str_repeat( 'c', 64 ) ), $outcome->cookies );
	}

	public function test_invalid_expired_revoked_and_wrong_purpose_keys_are_404(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->method( 'exchange' )->willReturn( null ); // exchange() не различает причины отказа
		$this->rate->expects( self::once() )->method( 'registerInvitationFailure' );
		$this->sessions->expects( self::never() )->method( 'openEntry' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleEntryPage()->kind );
	}

	public function test_key_without_admission_is_404(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->method( 'exchange' )->willReturn( $this->token() );
		$this->participations->method( 'find' )->willReturn( $this->participation( array( 'admitted_at' => null ) ) );
		$this->sessions->expects( self::never() )->method( 'openEntry' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleEntryPage()->kind );
	}

	public function test_page_without_cookie_is_404(): void {
		$this->sessions->method( 'current' )->willReturn( null );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleEntryPage()->kind );
	}

	public function test_locked_ip_gets_404_without_touching_tokens(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->rate->method( 'isInvitationLocked' )->willReturn( true );
		$this->tokens->expects( self::never() )->method( 'exchange' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleEntryPage()->kind );
	}

	public function test_no_wp_login_is_created(): void {
		$this->givenValidKey();
		$this->sessions->method( 'openEntry' )->willReturn( str_repeat( 'c', 64 ) );

		$this->cb->handleEntryPage();

		self::assertSame( 0, get_current_user_id() );
		$root = dirname( __DIR__, 4 ) . '/inc';
		foreach ( array( '/Callbacks/Exam/GuestEntryCallbacks.php', '/Services/Exam/GuestSessionService.php' ) as $file ) {
			self::assertDoesNotMatchRegularExpression( '/wp_set_auth_cookie|wp_set_current_user|wp_insert_user|wp_create_user/', (string) file_get_contents( $root . $file ) );
		}
	}

	// ── Страница результата (11b.3) и завершение сеанса (11b.4) ───────────────────────────────────

	public function test_result_page_requires_guest_session_of_same_participation(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( null );
		$this->result->expects( self::never() )->method( 'build' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleResultPage()->kind );
	}

	public function test_result_page_ignores_attempt_id_param(): void {
		$_GET['attempt_id'] = '999';
		$_GET['attempt']    = '999';
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->result->expects( self::once() )->method( 'build' )->with( 4 )->willReturn( array( 'revealed' => true ) );

		self::assertSame( GuestPageOutcome::RENDER, $this->cb->handleResultPage()->kind );
	}

	public function test_result_page_before_submit_is_404(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->result->method( 'build' )->willReturn( array( 'revealed' => false ) );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleResultPage()->kind );
	}

	public function test_result_key_opens_result_and_redirects_without_key(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->expects( self::once() )->method( 'exchange' )->with( ExamTokenPurpose::Result, str_repeat( 'a', 64 ) )->willReturn( $this->token() );
		$this->participations->method( 'find' )->willReturn( $this->participation( array( 'current_attempt_id' => '55' ) ) );
		$this->sessions->expects( self::once() )->method( 'openResult' )->willReturn( str_repeat( 'd', 64 ) );

		$outcome = $this->cb->handleResultPage();

		self::assertSame( GuestPageOutcome::REDIRECT, $outcome->kind );
		self::assertStringNotContainsString( 'k=', $outcome->url );
		self::assertSame( array( GuestSessionService::COOKIE_RESULT => str_repeat( 'd', 64 ) ), $outcome->cookies );
	}

	public function test_revoked_result_key_is_404(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->method( 'exchange' )->willReturn( null );
		$this->rate->expects( self::once() )->method( 'registerInvitationFailure' );
		$this->sessions->expects( self::never() )->method( 'openResult' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleResultPage()->kind );
	}

	public function test_result_key_without_submitted_attempt_is_404(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->method( 'exchange' )->willReturn( $this->token() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->sessions->expects( self::never() )->method( 'openResult' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleResultPage()->kind );
	}

	public function test_entry_key_on_result_page_is_404(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		// exchange(Result, …) по ключу входа возвращает null: назначение не совпадает.
		$this->tokens->expects( self::once() )->method( 'exchange' )->with( ExamTokenPurpose::Result, self::anything() )->willReturn( null );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleResultPage()->kind );
	}

	public function test_result_session_cannot_start_attempt(): void {
		// Начать попытку можно только по сессии входа: контекст попытки строит current(), который сессий результата не принимает.
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/inc/Services/Exam/GuestSessionService.php' );
		preg_match( '/public function current\(.*?\n\t}\n/s', $source, $m );

		self::assertStringContainsString( 'SCOPE_ENTRY', $m[0] );
		self::assertStringNotContainsString( 'SCOPE_RESULT', $m[0] );
	}

	public function test_end_session_revokes_and_clears_cookie(): void {
		$_COOKIE[ GuestSessionService::COOKIE_ENTRY ] = str_repeat( 'e', 64 );
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->result->method( 'build' )->willReturn( array( 'revealed' => true ) );
		$this->sessions->expects( self::once() )->method( 'revoke' )->with( str_repeat( 'e', 64 ) );
		$this->responder->expects( self::once() )->method( 'clearCookies' )->with( array( GuestSessionService::COOKIE_ENTRY, GuestSessionService::COOKIE_RESULT ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxEndExamGuestSession() );

		self::assertTrue( $r->success );
		self::assertSame( home_url( '/' ), $r->payload['url'] );
	}

	public function test_end_session_denied_before_submit(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->result->method( 'build' )->willReturn( array( 'revealed' => false ) );
		$this->sessions->expects( self::never() )->method( 'revoke' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxEndExamGuestSession() )->success );
	}

	public function test_result_page_after_end_session_is_404(): void {
		// После отзыва сессии viewableParticipationId() возвращает null — страница отдаёт 404.
		$this->sessions->method( 'viewableParticipationId' )->willReturn( null );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleResultPage()->kind );
		self::assertSame( '', $this->cb->renderResultPage() );
	}
}
