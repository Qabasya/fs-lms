<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamAccessTokenRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ExamAccessTokenServiceTest extends TestCase {

	private ExamAccessTokenRepository&MockObject $tokens;
	private PiiCryptoService&MockObject $crypto;
	private ExamAccessTokenService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->tokens = $this->createMock( ExamAccessTokenRepository::class );
		$this->crypto = $this->createMock( PiiCryptoService::class );
		$this->crypto->method( 'hash' )->willReturnCallback( static fn ( string $v ): string => hash( 'sha256', 'salt' . $v ) );

		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 09:00:00' );

		$this->service = new ExamAccessTokenService( $this->tokens, $this->crypto, $time );
	}

	private function token( string $purpose = 'invitation', ?string $revokedAt = null, ?string $expiresAt = null ): ExamAccessTokenDTO {
		return ExamAccessTokenDTO::fromArray( array(
			'id' => 5, 'purpose' => $purpose, 'target_id' => 3, 'token_hash' => 'h', 'generation' => 2, 'expires_at' => $expiresAt,
			'revoked_at' => $revokedAt, 'issuer_user_id' => 1, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_issue_returns_64_hex_chars_and_stores_only_hash(): void {
		$stored = null;
		$this->tokens->method( 'maxGeneration' )->willReturn( 0 );
		$this->tokens->method( 'insert' )->willReturnCallback( function ( array $row ) use ( &$stored ): int {
			$stored = $row;
			return 11;
		} );

		$plain = $this->service->issue( ExamTokenPurpose::Invitation, 3, 1 );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $plain );
		self::assertSame( hash( 'sha256', 'salt' . $plain ), $stored['token_hash'] );
		self::assertNotContains( $plain, array_map( 'strval', $stored ), 'Открытый ключ в строку не попадает.' );
		self::assertSame( 'invitation', $stored['purpose'] );
		self::assertSame( 3, $stored['target_id'] );
	}

	public function test_issue_revokes_previous_token_and_increments_generation(): void {
		$this->tokens->expects( self::once() )->method( 'revokeByTarget' )->with( ExamTokenPurpose::Invitation, 3, '2026-03-10 09:00:00' );
		$this->tokens->method( 'maxGeneration' )->willReturn( 4 );
		$stored = null;
		$this->tokens->method( 'insert' )->willReturnCallback( function ( array $row ) use ( &$stored ): int {
			$stored = $row;
			return 1;
		} );

		$this->service->issue( ExamTokenPurpose::Invitation, 3, 1, '2026-03-31 20:59:59' );

		self::assertSame( 5, $stored['generation'] );
		self::assertSame( '2026-03-31 20:59:59', $stored['expires_at'] );
	}

	public function test_issue_fails_loudly_when_row_was_not_saved(): void {
		$this->tokens->method( 'maxGeneration' )->willReturn( 0 );
		$this->tokens->method( 'insert' )->willReturn( 0 );

		$this->expectException( \RuntimeException::class );

		$this->service->issue( ExamTokenPurpose::Entry, 1, 1 );
	}

	public function test_two_issued_tokens_differ(): void {
		$this->tokens->method( 'maxGeneration' )->willReturn( 0 );
		$this->tokens->method( 'insert' )->willReturn( 1 );

		self::assertNotSame(
			$this->service->issue( ExamTokenPurpose::Result, 1, 1 ),
			$this->service->issue( ExamTokenPurpose::Result, 1, 1 )
		);
	}

	public function test_exchange_returns_null_for_malformed_key_without_db_lookup(): void {
		$this->tokens->expects( self::never() )->method( 'findByHash' );

		foreach ( array( '', 'abc', str_repeat( 'g', 64 ), strtoupper( str_repeat( 'a', 64 ) ), str_repeat( 'a', 63 ), str_repeat( 'a', 65 ) ) as $bad ) {
			self::assertNull( $this->service->exchange( ExamTokenPurpose::Invitation, $bad ), "'{$bad}'" );
		}
	}

	public function test_exchange_accepts_valid_token(): void {
		$token = $this->token();
		$this->tokens->method( 'findByHash' )->willReturn( $token );

		self::assertSame( $token, $this->service->exchange( ExamTokenPurpose::Invitation, str_repeat( 'a', 64 ) ) );
	}

	public function test_exchange_rejects_other_purpose(): void {
		$this->tokens->method( 'findByHash' )->willReturn( $this->token( 'entry' ) );

		self::assertNull( $this->service->exchange( ExamTokenPurpose::Result, str_repeat( 'a', 64 ) ) );
	}

	public function test_exchange_rejects_revoked(): void {
		$this->tokens->method( 'findByHash' )->willReturn( $this->token( 'invitation', '2026-03-09 00:00:00' ) );

		self::assertNull( $this->service->exchange( ExamTokenPurpose::Invitation, str_repeat( 'a', 64 ) ) );
	}

	public function test_exchange_rejects_expired(): void {
		$this->tokens->method( 'findByHash' )->willReturn( $this->token( 'invitation', null, '2026-03-10 09:00:00' ) );

		self::assertNull( $this->service->exchange( ExamTokenPurpose::Invitation, str_repeat( 'a', 64 ) ), 'Срок наступил ровно сейчас — ключ недействителен.' );
	}

	public function test_exchange_rejects_unknown_key(): void {
		$this->tokens->method( 'findByHash' )->willReturn( null );

		self::assertNull( $this->service->exchange( ExamTokenPurpose::Invitation, str_repeat( 'a', 64 ) ) );
	}

	public function test_current_generation_is_zero_without_active_token(): void {
		$this->tokens->method( 'findActive' )->willReturn( null );

		self::assertSame( 0, $this->service->currentGeneration( ExamTokenPurpose::Entry, 7 ) );
		self::assertFalse( $this->service->hasActive( ExamTokenPurpose::Entry, 7 ) );
	}

	public function test_current_generation_comes_from_active_token(): void {
		$this->tokens->method( 'findActive' )->willReturn( $this->token() );

		self::assertSame( 2, $this->service->currentGeneration( ExamTokenPurpose::Invitation, 3 ) );
	}

	public function test_revoke_returns_number_of_revoked_tokens(): void {
		$this->tokens->expects( self::once() )->method( 'revokeByTarget' )->with( ExamTokenPurpose::Report, 9, '2026-03-10 09:00:00' )->willReturn( 2 );

		self::assertSame( 2, $this->service->revoke( ExamTokenPurpose::Report, 9 ) );
	}

	public function test_mark_passed_is_manual_and_records_actor(): void {
		$this->tokens->expects( self::once() )->method( 'markPassed' )->with( 5, 42, '2026-03-10 09:00:00' );

		$this->service->markPassed( 5, 42 );
	}
}
