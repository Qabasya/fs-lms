<?php

declare(strict_types=1);

namespace Unit\Services\Email;

use Inc\Services\Email\EmailOtpService;
use Inc\Services\Email\EmailService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class EmailOtpServiceTest extends TestCase {

	private EmailOtpService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_transients'] = [];
		$emailService = $this->createMock( EmailService::class );
		$emailService->method( 'sendOtpCode' )->willReturn( true );

		$pluginConfig = $this->createMock( PluginConfig::class );
		$pluginConfig->method( 'otpBypassCode' )->willReturn( FS_LMS_OTP_BYPASS_CODE );

		$this->service = new EmailOtpService( $emailService, $pluginConfig );
	}

	private function otpKey( string $email ): string {
		return 'fs_lms_otp_' . hash( 'sha256', $email );
	}

	private function cooldownKey( string $email ): string {
		return 'fs_lms_otp_cd_' . hash( 'sha256', $email );
	}

	private function storeCode( string $email, string $code ): void {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';
		set_transient( $this->otpKey( $email ), hash( 'sha256', $code . $salt ) );
	}

	// ── verify() ────────────────────────────────────────────────────────────────

	public function test_verify_returns_true_and_deletes_transient_on_correct_code(): void {
		$email = 'student@test.com';
		$code  = '654321';
		$this->storeCode( $email, $code );

		$result = $this->service->verify( $email, $code );

		self::assertTrue( $result );
		self::assertFalse( get_transient( $this->otpKey( $email ) ), 'Transient должен быть удалён после верификации' );
	}

	public function test_verify_returns_false_on_wrong_code(): void {
		$email = 'student@test.com';
		$this->storeCode( $email, '111111' );

		self::assertFalse( $this->service->verify( $email, '999999' ) );
	}

	public function test_verify_returns_false_when_transient_does_not_exist(): void {
		self::assertFalse( $this->service->verify( 'nobody@test.com', '123456' ) );
	}

	// ── canResend() ─────────────────────────────────────────────────────────────

	public function test_can_resend_returns_false_while_cooldown_is_active(): void {
		$email = 'student@test.com';
		set_transient( $this->cooldownKey( $email ), 1 );

		self::assertFalse( $this->service->canResend( $email ) );
	}

	public function test_can_resend_returns_true_when_no_cooldown(): void {
		self::assertTrue( $this->service->canResend( 'student@test.com' ) );
	}

	// ── bypass ──────────────────────────────────────────────────────────────────

	public function test_verify_returns_true_with_bypass_code(): void {
		self::assertTrue( $this->service->verify( 'student@test.com', FS_LMS_OTP_BYPASS_CODE ) );
	}
}
