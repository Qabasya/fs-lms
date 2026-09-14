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

	/** @param string[] $trustedIps */
	private function makeService( bool $testEnv = false, array $trustedIps = array() ): RateLimitService {
		$config = $this->createStub( PluginConfig::class );
		$config->method( 'isTestEnv' )->willReturn( $testEnv );
		$config->method( 'trustedIps' )->willReturn( $trustedIps );
		return new RateLimitService( $config );
	}

	/** Сколько вызовов подряд проходит до первого отказа (с потолком, чтобы не зациклиться). */
	private function passesBeforeBlock( callable $attempt, int $ceiling = 1000 ): int {
		for ( $i = 0; $i < $ceiling; $i++ ) {
			if ( ! $attempt() ) {
				return $i;
			}
		}
		return $ceiling;
	}

	// ── Заявка: отдельные счётчики кода и создания ───────────────────────────────

	public function test_otp_send_and_application_creation_have_separate_counters(): void {
		$service = $this->makeService();
		$ip      = '5.5.5.5';

		// Исчерпываем лимит отправок кода (LIMIT_APPLICATION = 20).
		self::assertSame( 20, $this->passesBeforeBlock( fn() => $service->allowOtpSend( $ip ) ) );

		// Создание заявки с того же IP не затронуто.
		self::assertTrue( $service->allowApplicationCreation( $ip ) );
	}

	public function test_twenty_students_behind_one_ip_fit_default_limits(): void {
		$service = $this->makeService();
		$ip      = '5.5.5.5';

		// Каждый ученик: одна отправка кода и одно создание заявки.
		for ( $i = 1; $i <= 20; $i++ ) {
			self::assertTrue( $service->allowOtpSend( $ip ), "Код ученика #$i" );
			self::assertTrue( $service->allowApplicationCreation( $ip ), "Заявка ученика #$i" );
		}
	}

	// ── Белые IP ─────────────────────────────────────────────────────────────────

	public function test_trusted_ip_gets_limits_multiplied_by_ten(): void {
		$service = $this->makeService( trustedIps: array( '9.9.9.9' ) );

		self::assertSame( 200, $this->passesBeforeBlock( fn() => $service->allowOtpSend( '9.9.9.9' ) ) );
		self::assertSame( 200, $this->passesBeforeBlock( fn() => $service->allowUsernameCheck( '9.9.9.9' ) ) );
		self::assertSame( 30, $this->passesBeforeBlock( fn() => $service->allowParentSubmit( '9.9.9.9' ) ) );
	}

	public function test_other_ips_keep_default_limits_when_trusted_list_is_set(): void {
		$service = $this->makeService( trustedIps: array( '9.9.9.9' ) );

		self::assertSame( 20, $this->passesBeforeBlock( fn() => $service->allowOtpSend( '5.5.5.5' ) ) );
		self::assertSame( 3, $this->passesBeforeBlock( fn() => $service->allowParentSubmit( '5.5.5.5' ) ) );
	}

	public function test_trusted_ip_does_not_raise_per_email_limit(): void {
		$service = $this->makeService( trustedIps: array( '9.9.9.9' ) );

		self::assertSame( 5, $this->passesBeforeBlock( fn() => $service->allowOtpSendForEmail( 'student@test.com' ) ) );
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

	// ── Неудачные входы (пара IP + пользователь) ─────────────────────────────────

	public function test_third_login_failure_locks_pair(): void {
		$service = $this->makeService();

		self::assertSame( 2, $service->registerLoginFailure( '1.1.1.1', 'id:7' ) );
		self::assertSame( 1, $service->registerLoginFailure( '1.1.1.1', 'id:7' ) );
		self::assertFalse( $service->isLoginLocked( '1.1.1.1', 'id:7' ) );

		self::assertSame( 0, $service->registerLoginFailure( '1.1.1.1', 'id:7' ) );
		self::assertTrue( $service->isLoginLocked( '1.1.1.1', 'id:7' ) );
	}

	public function test_lock_check_does_not_increment(): void {
		$service = $this->makeService();

		$service->registerLoginFailure( '1.1.1.1', 'id:7' );
		for ( $i = 0; $i < 5; $i++ ) {
			$service->isLoginLocked( '1.1.1.1', 'id:7' );
		}

		self::assertSame( 2, $service->loginAttemptsLeft( '1.1.1.1', 'id:7' ) );
	}

	public function test_other_login_from_same_ip_is_not_affected(): void {
		$service = $this->makeService();

		for ( $i = 0; $i < 3; $i++ ) {
			$service->registerLoginFailure( '1.1.1.1', 'id:7' );
		}

		self::assertFalse( $service->isLoginLocked( '1.1.1.1', 'id:8' ) );
		self::assertFalse( $service->isLoginLocked( '2.2.2.2', 'id:7' ) );
	}

	public function test_pair_opens_after_window_expires(): void {
		$service = $this->makeService();

		for ( $i = 0; $i < 3; $i++ ) {
			$service->registerLoginFailure( '1.1.1.1', 'id:7' );
		}
		$key = $service->loginKey( '1.1.1.1', 'id:7' );
		$GLOBALS['_test_transients'][ $key ]['reset_at'] = time() - 1;

		self::assertFalse( $service->isLoginLocked( '1.1.1.1', 'id:7' ) );
		self::assertSame( 0, $service->loginRetryAfter( '1.1.1.1', 'id:7' ) );
		// Первая неудача после окна начинает новый отсчёт.
		self::assertSame( 2, $service->registerLoginFailure( '1.1.1.1', 'id:7' ) );
	}

	public function test_clear_resets_counter(): void {
		$service = $this->makeService();

		for ( $i = 0; $i < 3; $i++ ) {
			$service->registerLoginFailure( '1.1.1.1', 'id:7' );
		}
		$service->clearLoginFailures( '1.1.1.1', 'id:7' );

		self::assertFalse( $service->isLoginLocked( '1.1.1.1', 'id:7' ) );
		self::assertSame( 3, $service->loginAttemptsLeft( '1.1.1.1', 'id:7' ) );
	}

	public function test_retry_after_is_rounded_up_minutes(): void {
		$service = $this->makeService();

		$service->registerLoginFailure( '1.1.1.1', 'id:7' );

		self::assertSame( 15, $service->loginRetryAfter( '1.1.1.1', 'id:7' ) );
	}

	public function test_test_env_does_not_disable_login_limit(): void {
		$service = $this->makeService( testEnv: true );

		for ( $i = 0; $i < 3; $i++ ) {
			$service->registerLoginFailure( '1.1.1.1', 'id:7' );
		}

		self::assertTrue( $service->isLoginLocked( '1.1.1.1', 'id:7' ) );
	}
}
