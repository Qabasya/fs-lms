<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use Inc\Services\Security\FormGuardService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class FormGuardServiceTest extends TestCase {

	private function makeService( bool $testEnv = false ): FormGuardService {
		$config = $this->createMock( PluginConfig::class );
		$config->method( 'isTestEnv' )->willReturn( $testEnv );
		return new FormGuardService( $config );
	}

	private function sign( string $ts ): string {
		return hash_hmac( 'sha256', $ts, FS_LMS_HASH_SALT );
	}

	private function tokenFor( int $tsOffsetSeconds ): string {
		$ts = (string) ( time() + $tsOffsetSeconds );
		return $ts . '.' . $this->sign( $ts );
	}

	// ── Honeypot ──────────────────────────────────────────────────────────────────

	public function test_rejects_when_honeypot_filled(): void {
		$service = $this->makeService();
		self::assertFalse( $service->isHuman( 'bot-value', $this->tokenFor( -10 ) ) );
	}

	public function test_accepts_valid_token_with_empty_honeypot(): void {
		$service = $this->makeService();
		self::assertTrue( $service->isHuman( '', $this->tokenFor( -10 ) ) );
		self::assertTrue( $service->isHuman( '   ', $this->tokenFor( -10 ) ) );
	}

	// ── Тайминг ─────────────────────────────────────────────────────────────────

	public function test_rejects_too_fast_submission(): void {
		$service = $this->makeService();
		// Форма отправлена мгновенно (elapsed 0 < MIN_FILL_SECONDS).
		self::assertFalse( $service->isHuman( '', $this->tokenFor( 0 ) ) );
	}

	public function test_rejects_stale_token(): void {
		$service = $this->makeService();
		// Токен старше суток (> MAX_TOKEN_AGE).
		self::assertFalse( $service->isHuman( '', $this->tokenFor( -90000 ) ) );
	}

	// ── Подпись ───────────────────────────────────────────────────────────────────

	public function test_rejects_tampered_signature(): void {
		$service = $this->makeService();
		$ts      = (string) ( time() - 10 );
		self::assertFalse( $service->isHuman( '', $ts . '.deadbeef' ) );
	}

	public function test_rejects_malformed_token(): void {
		$service = $this->makeService();
		self::assertFalse( $service->isHuman( '', 'garbage' ) );
		self::assertFalse( $service->isHuman( '', '' ) );
		self::assertFalse( $service->isHuman( '', 'notdigits.' . $this->sign( 'notdigits' ) ) );
	}

	// ── Round-trip и test-env ─────────────────────────────────────────────────────

	public function test_freshly_generated_token_is_too_fast_by_design(): void {
		$service = $this->makeService();
		// Только что выданный токен → elapsed ~0 → отклоняется (человек так быстро не успеет).
		self::assertFalse( $service->isHuman( '', $service->timestampToken() ) );
	}

	public function test_test_env_bypasses_all_checks(): void {
		$service = $this->makeService( testEnv: true );
		self::assertTrue( $service->isHuman( 'bot-value', 'garbage' ) );
	}
}
