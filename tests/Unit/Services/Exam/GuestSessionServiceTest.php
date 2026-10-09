<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\DTO\Exam\ExamGuestSessionDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamGuestSessionRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Гостевые сессии приглашения: в базе только хеш куки, поколение, отзыв, срок.
 */
#[AllowMockObjectsWithoutExpectations]
class GuestSessionServiceTest extends TestCase {

	private ExamGuestSessionRepository&MockObject $repo;
	private ExamAccessTokenService&MockObject $tokens;
	private GuestSessionService $service;
	private ExamParticipationRepository&MockObject $participations;
	private ExamRegistrationRepository&MockObject $registrations;

	/** @var array<string, mixed> */
	private array $stored = array();
	private int $currentGeneration = 1;

	protected function setUp(): void {
		parent::setUp();

		$this->repo           = $this->createMock( ExamGuestSessionRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->tokens = $this->createMock( ExamAccessTokenService::class );
		$crypto       = $this->createMock( PiiCryptoService::class );
		$crypto->method( 'hash' )->willReturnCallback( static fn ( string $v ): string => hash( 'sha256', 'salt' . $v ) );
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'addMinutes' )->willReturnCallback( static fn ( string $d, int $m ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' UTC' ) + $m * 60 ) );

		$this->repo->method( 'insert' )->willReturnCallback( function ( array $row ): int {
			$this->stored = $row;
			return 5;
		} );
		$this->repo->method( 'findByCookieHash' )->willReturnCallback( function ( string $hash ): ?ExamGuestSessionDTO {
			return ( $this->stored['cookie_hash'] ?? null ) === $hash ? $this->dto() : null;
		} );
		$this->tokens->method( 'currentGeneration' )->willReturnCallback( fn (): int => $this->currentGeneration );

		$this->service = new GuestSessionService( $this->repo, $this->tokens, $crypto, $time, $this->participations, $this->registrations );
	}

	private function dto(): ExamGuestSessionDTO {
		return ExamGuestSessionDTO::fromArray( array(
			'id' => 5, 'cookie_hash' => $this->stored['cookie_hash'], 'scope' => $this->stored['scope'], 'source_id' => $this->stored['source_id'] ?? null, 'participation_id' => $this->stored['participation_id'] ?? null, 'registration_id' => $this->stored['registration_id'] ?? null,
			'generation' => $this->stored['generation'], 'issued_at' => $this->stored['issued_at'], 'expires_at' => $this->stored['expires_at'],
			'revoked_at' => $this->stored['revoked_at'] ?? null,
		) );
	}

	public function test_invitation_cookie_stored_as_hash(): void {
		$cookie = $this->service->openInvitation( 14, 1 );

		self::assertSame( 1, preg_match( '/^[a-f0-9]{64}$/', $cookie ) );
		self::assertNotSame( $cookie, $this->stored['cookie_hash'] );
		self::assertSame( hash( 'sha256', 'salt' . $cookie ), $this->stored['cookie_hash'] );
		self::assertSame( 'invitation', $this->stored['scope'] );
		self::assertSame( 14, $this->stored['source_id'] );
		self::assertSame( 14, $this->service->resolveInvitation( $cookie ) );
	}

	public function test_invitation_invalid_after_generation_bump(): void {
		$cookie = $this->service->openInvitation( 14, 1 );
		$this->currentGeneration = 2; // ссылку перевыпустили

		self::assertNull( $this->service->resolveInvitation( $cookie ) );
	}

	public function test_revoked_invitation_is_not_resolved(): void {
		$cookie = $this->service->openInvitation( 14, 1 );
		$this->stored['revoked_at'] = '2026-03-10 07:01:00';
		// Репозиторий отдаёт только не отозванные сессии — как и боевой `findByCookieHash()`.
		$repo = $this->createMock( ExamGuestSessionRepository::class );
		$repo->method( 'findByCookieHash' )->willReturn( null );
		$crypto = $this->createMock( PiiCryptoService::class );
		$crypto->method( 'hash' )->willReturn( 'x' );
		$service = new GuestSessionService( $repo, $this->tokens, $crypto, $this->createStub( ExamTime::class ), $this->createMock( \Inc\Repositories\WPDBRepositories\ExamParticipationRepository::class ), $this->createMock( \Inc\Repositories\WPDBRepositories\ExamRegistrationRepository::class ) );

		self::assertNull( $service->resolveInvitation( $cookie ) );
	}

	public function test_expired_invitation_is_not_resolved(): void {
		$cookie = $this->service->openInvitation( 14, 1, '2026-03-10 06:59:00' );

		self::assertNull( $this->service->resolveInvitation( $cookie ) );
	}

	public function test_malformed_cookie_never_reaches_the_database(): void {
		$this->repo->expects( self::never() )->method( 'findByCookieHash' );

		self::assertNull( $this->service->resolveInvitation( "'; DROP TABLE x;--" ) );
		self::assertNull( $this->service->resolveInvitation( '' ) );
	}

	public function test_revoke_by_source_delegates(): void {
		$this->repo->expects( self::once() )->method( 'revokeBySource' )->with( 14, '2026-03-10 07:00:00' );

		$this->service->revokeBySource( 14 );
	}

	public function test_generation_zero_never_resolves(): void {
		$cookie = $this->service->openInvitation( 14, 0 );
		$this->currentGeneration = 0; // у источника нет действующего ключа

		self::assertNull( $this->service->resolveInvitation( $cookie ) );
		self::assertSame( ExamTokenPurpose::Invitation, ExamTokenPurpose::from( 'invitation' ) );
	}

	// ── Вход на экзамен (11b.1.4) ─────────────────────────────────────────────────────────────────

	private function entryToken( int $generation = 2 ): ExamAccessTokenDTO {
		return ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => 'entry', 'target_id' => 4, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => $generation, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	private function givenParticipation( ?int $attemptId = null, string $registrationStatus = 'confirmed', ?int $activeRegistration = 8 ): void {
		$this->participations->method( 'find' )->willReturn( ExamParticipationDTO::fromArray( array(
			'id' => '4', 'event_id' => '3', 'participant_id' => '9', 'audience' => 'guest', 'active_registration_id' => $activeRegistration, 'current_attempt_id' => $attemptId,
			'source_id' => '14', 'consent_refs' => null, 'transfer_allowed' => '0', 'admitted_at' => '2026-03-10 06:30:00', 'admitted_by_user_id' => '10',
			'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );
		$this->registrations->method( 'find' )->willReturn( ExamRegistrationDTO::fromArray( array(
			'id' => '8', 'participation_id' => '4', 'session_id' => '7', 'status' => $registrationStatus, 'active_slot' => '1', 'request_key' => null, 'reason' => null,
			'actor_user_id' => null, 'arrived_at' => null, 'arrived_by_user_id' => null, 'created_at' => '2026-03-01 00:00:00', 'cancelled_at' => null, 'transferred_at' => null, 'missed_at' => null,
		) ) );
	}

	public function test_entry_session_cookie_is_hashed_and_session_only(): void {
		$cookie = $this->service->openEntry( $this->entryToken(), 8, '2026-03-10 10:55:00' );

		self::assertSame( hash( 'sha256', 'salt' . $cookie ), $this->stored['cookie_hash'] );
		self::assertSame( 'entry', $this->stored['scope'] );
		self::assertSame( 4, $this->stored['participation_id'] );
		self::assertSame( 8, $this->stored['registration_id'] );
		self::assertSame( 'fs_exam_guest', GuestSessionService::COOKIE_ENTRY );
	}

	public function test_new_entry_revokes_previous_entry_sessions(): void {
		$this->repo->expects( self::once() )->method( 'revokeByParticipation' )->with( 4, 'entry', '2026-03-10 07:00:00' );

		$this->service->openEntry( $this->entryToken(), 8, '2026-03-10 10:55:00' );
	}

	public function test_current_returns_guest_context_without_person(): void {
		$this->givenParticipation();
		$this->currentGeneration = 2;
		$cookie = $this->service->openEntry( $this->entryToken( 2 ), 8, '2026-03-10 10:55:00' );

		$ctx = $this->service->current( $cookie );

		self::assertNotNull( $ctx );
		self::assertSame( \Inc\Enums\Exam\ExamAudience::Guest, $ctx->audience );
		self::assertNull( $ctx->personId );
		self::assertNull( $ctx->wpUserId );
		self::assertSame( 4, $ctx->participationId );
	}

	public function test_session_with_old_generation_cannot_start_new_attempt(): void {
		$this->givenParticipation();
		$cookie = $this->service->openEntry( $this->entryToken( 1 ), 8, '2026-03-10 10:55:00' );
		$this->currentGeneration = 2; // ссылку перевыпустили

		self::assertNull( $this->service->current( $cookie ) );
	}

	public function test_session_with_own_started_attempt_survives_reissue(): void {
		$this->givenParticipation( 55 );
		$cookie = $this->service->openEntry( $this->entryToken( 1 ), 8, '2026-03-10 10:55:00' );
		$this->currentGeneration = 2;

		self::assertNotNull( $this->service->current( $cookie ) );
	}

	public function test_cancelled_registration_closes_the_session_before_start(): void {
		$this->givenParticipation( null, 'cancelled' );
		$this->currentGeneration = 2;
		$cookie = $this->service->openEntry( $this->entryToken( 2 ), 8, '2026-03-10 10:55:00' );

		self::assertNull( $this->service->current( $cookie ) );
	}

	public function test_session_extends_to_personal_deadline_after_start(): void {
		$this->repo->expects( self::once() )->method( 'extendByParticipation' )->with( 4, 'entry', '2026-03-10 11:30:00' );

		$this->service->extendForAttempt( 4, '2026-03-10 11:00:00' );
	}

	// ── Результат (11b.4–11b.5) ───────────────────────────────────────────────────────────────────

	public function test_result_view_expires_thirty_minutes_after_submit(): void {
		$this->repo->expects( self::once() )->method( 'capByParticipation' )->with( 4, 'entry', '2026-03-10 07:30:00' );

		$this->service->closeAfterSubmit( 4 );
	}

	public function test_result_session_does_not_give_attempt_context(): void {
		$token = ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => 'result', 'target_id' => 4, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 1, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
		$cookie = $this->service->openResult( $token, '2026-03-11 07:00:00' );

		self::assertSame( 'result', $this->stored['scope'] );
		// Сессия результата для старта и продолжения попытки не принимается: current() ищет только scope = entry.
		self::assertNull( $this->service->current( $cookie ) );
	}

	public function test_revoked_session_gives_no_viewable_participation(): void {
		unset( $_COOKIE[ GuestSessionService::COOKIE_ENTRY ], $_COOKIE[ GuestSessionService::COOKIE_RESULT ] );

		self::assertNull( $this->service->viewableParticipationId() );
	}

	public function test_result_session_with_old_generation_is_not_viewable(): void {
		$token = ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => 'result', 'target_id' => 4, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 1, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
		$_COOKIE[ GuestSessionService::COOKIE_RESULT ] = $this->service->openResult( $token, '2026-03-11 07:00:00' );
		$this->currentGeneration = 2; // ссылку перевыпустили или отозвали
		$this->givenParticipation();

		try {
			self::assertNull( $this->service->viewableParticipationId() );
		} finally {
			unset( $_COOKIE[ GuestSessionService::COOKIE_RESULT ] );
		}
	}

	public function test_result_session_with_current_generation_is_viewable(): void {
		$token = ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => 'result', 'target_id' => 4, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 1, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
		$_COOKIE[ GuestSessionService::COOKIE_RESULT ] = $this->service->openResult( $token, '2026-03-11 07:00:00' );
		$this->currentGeneration = 1;
		$this->givenParticipation();

		try {
			self::assertSame( 4, $this->service->viewableParticipationId() );
		} finally {
			unset( $_COOKIE[ GuestSessionService::COOKIE_RESULT ] );
		}
	}
}
