<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use Inc\Services\Security\RateLimitService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class RateLimitServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_transients'] = array();
	}

	private function makeService( bool $testEnv = false ): RateLimitService {
		$config = $this->createMock( PluginConfig::class );
		$config->method( 'isTestEnv' )->willReturn( $testEnv );
		return new RateLimitService( $config );
	}

	// ── Per-email OTP-лимит ───────────────────────────────────────────────────────

	public function test_allows_otp_sends_up_to_limit_then_blocks(): void {
		$service = $this->makeService();
		$email   = 'student@test.com';

		// LIMIT_OTP_EMAIL = 5 — первые пять проходят.
		for ( $i = 1; $i <= 5; $i++ ) {
			self::assertTrue( $service->allowOtpSendForEmail( $email ), "Отправка #$i должна пройти" );
		}

		// Шестая — заблокирована.
		self::assertFalse( $service->allowOtpSendForEmail( $email ) );
	}

	public function test_limit_is_per_email_not_global(): void {
		$service = $this->makeService();

		for ( $i = 1; $i <= 5; $i++ ) {
			$service->allowOtpSendForEmail( 'a@test.com' );
		}

		// Другой адрес не затронут чужим счётчиком.
		self::assertTrue( $service->allowOtpSendForEmail( 'b@test.com' ) );
	}

	public function test_email_key_normalizes_case_and_whitespace(): void {
		$service = $this->makeService();

		self::assertSame(
			$service->emailKey( 'otpmail', 'Student@Test.com' ),
			$service->emailKey( 'otpmail', '  student@test.com  ' )
		);
	}

	public function test_test_env_disables_email_limit(): void {
		$service = $this->makeService( testEnv: true );
		$email   = 'student@test.com';

		for ( $i = 1; $i <= 20; $i++ ) {
			self::assertTrue( $service->allowOtpSendForEmail( $email ) );
		}
	}
}
