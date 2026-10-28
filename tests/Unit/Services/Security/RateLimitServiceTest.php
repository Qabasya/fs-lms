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
		$config->method( 'isTrustedIp' )->willReturnCallback(
			static fn( string $ip ): bool => in_array( $ip, $trustedIps, true )
		);
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
	}

	public function test_other_ips_keep_default_limits_when_trusted_list_is_set(): void {
		$service = $this->makeService( trustedIps: array( '9.9.9.9' ) );

		self::assertSame( 20, $this->passesBeforeBlock( fn() => $service->allowOtpSend( '5.5.5.5' ) ) );
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

	private const IP   = '1.1.1.1';
	private const USER = 'id:7';

	private function failLogin( RateLimitService $service, int $times = 1 ): int {
		$left = -1;
		for ( $i = 0; $i < $times; $i++ ) {
			$left = $service->registerLoginFailure( self::IP, self::USER );
		}

		return $left;
	}

	/** Сдвигает время счётчика пары назад: окно неудач и блокировка кончаются раньше. */
	private function ageLogin( RateLimitService $service, int $seconds ): void {
		$key = $service->loginKey( self::IP, self::USER );
		foreach ( array( 'reset_at', 'locked_until' ) as $field ) {
			$GLOBALS['_test_transients'][ $key ][ $field ] -= $seconds;
		}
	}

	/** Блокировка пары → её конец → ступень остаётся в памяти. */
	private function lockAndWaitItOut( RateLimitService $service ): void {
		$this->failLogin( $service, RateLimitService::LIMIT_LOGIN );
		$this->ageLogin( $service, $service->loginRetryAfter( self::IP, self::USER ) * MINUTE_IN_SECONDS + 1 );
	}

	public function test_third_login_failure_locks_pair_for_fifteen_minutes(): void {
		$service = $this->makeService();

		self::assertSame( 3, RateLimitService::LIMIT_LOGIN );
		self::assertSame( 2, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertSame( 1, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertFalse( $service->isLoginLocked( self::IP, self::USER ) );

		self::assertSame( 0, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertTrue( $service->isLoginLocked( self::IP, self::USER ) );
		self::assertSame( 15, $service->loginRetryAfter( self::IP, self::USER ) );
	}

	public function test_lock_check_does_not_increment(): void {
		$service = $this->makeService();

		$this->failLogin( $service );
		for ( $i = 0; $i < 5; $i++ ) {
			$service->isLoginLocked( self::IP, self::USER );
		}

		self::assertSame( RateLimitService::LIMIT_LOGIN - 1, $service->loginAttemptsLeft( self::IP, self::USER ) );
	}

	public function test_other_login_from_same_ip_is_not_affected(): void {
		$service = $this->makeService();

		$this->failLogin( $service, RateLimitService::LIMIT_LOGIN );

		self::assertFalse( $service->isLoginLocked( self::IP, 'id:8' ) );
		self::assertFalse( $service->isLoginLocked( '2.2.2.2', self::USER ) );
	}

	public function test_failures_spread_over_more_than_five_minutes_do_not_lock(): void {
		$service = $this->makeService();

		$this->failLogin( $service, 2 );
		$this->ageLogin( $service, RateLimitService::LOGIN_WINDOW + 1 );

		// Окно прошлых неудач кончилось: третья неудача — первая в новом окне.
		self::assertSame( RateLimitService::LIMIT_LOGIN, $service->loginAttemptsLeft( self::IP, self::USER ) );
		self::assertSame( 2, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertFalse( $service->isLoginLocked( self::IP, self::USER ) );
	}

	public function test_pair_opens_after_lock_ends_with_fresh_attempts(): void {
		$service = $this->makeService();

		$this->lockAndWaitItOut( $service );

		self::assertFalse( $service->isLoginLocked( self::IP, self::USER ) );
		self::assertSame( 0, $service->loginRetryAfter( self::IP, self::USER ) );
		self::assertSame( RateLimitService::LIMIT_LOGIN, $service->loginAttemptsLeft( self::IP, self::USER ) );
		// Первая неудача после блокировки начинает новое окно.
		self::assertSame( 2, $service->registerLoginFailure( self::IP, self::USER ) );
	}

	public function test_lock_escalates_fifteen_minutes_then_hour_then_day(): void {
		$service = $this->makeService();

		$this->failLogin( $service, 3 );
		self::assertSame( 15, $service->loginRetryAfter( self::IP, self::USER ) );

		$this->ageLogin( $service, 15 * MINUTE_IN_SECONDS + 1 );
		$this->failLogin( $service, 3 );
		self::assertSame( 60, $service->loginRetryAfter( self::IP, self::USER ) );

		$this->ageLogin( $service, HOUR_IN_SECONDS + 1 );
		$this->failLogin( $service, 3 );
		self::assertSame( 24 * 60, $service->loginRetryAfter( self::IP, self::USER ) );

		// Дальше ступень не растёт: те же сутки.
		$this->ageLogin( $service, DAY_IN_SECONDS + 1 );
		$this->failLogin( $service, 3 );
		self::assertSame( 24 * 60, $service->loginRetryAfter( self::IP, self::USER ) );
	}

	public function test_failures_during_lock_neither_extend_it_nor_raise_the_step(): void {
		$service = $this->makeService();

		$this->failLogin( $service, 3 );
		$this->failLogin( $service, 10 );

		self::assertSame( 0, $service->loginAttemptsLeft( self::IP, self::USER ) );
		self::assertSame( 15, $service->loginRetryAfter( self::IP, self::USER ) );

		// После конца блокировки — вторая ступень (час), а не четвёртая.
		$this->ageLogin( $service, 15 * MINUTE_IN_SECONDS + 1 );
		$this->failLogin( $service, 3 );
		self::assertSame( 60, $service->loginRetryAfter( self::IP, self::USER ) );
	}

	public function test_step_is_forgotten_when_counter_expires(): void {
		$service = $this->makeService();

		$this->lockAndWaitItOut( $service );
		// Тихие сутки: transient с запасом LOGIN_LOCK_MEMORY истёк сам.
		unset( $GLOBALS['_test_transients'][ $service->loginKey( self::IP, self::USER ) ] );

		$this->failLogin( $service, 3 );
		self::assertSame( 15, $service->loginRetryAfter( self::IP, self::USER ) );
	}

	public function test_next_lock_minutes_follow_the_step(): void {
		$service = $this->makeService();

		self::assertSame( 15, $service->loginNextLockMinutes( self::IP, self::USER ) );

		$this->lockAndWaitItOut( $service );
		self::assertSame( 60, $service->loginNextLockMinutes( self::IP, self::USER ) );

		$this->lockAndWaitItOut( $service );
		self::assertSame( 24 * 60, $service->loginNextLockMinutes( self::IP, self::USER ) );

		$this->lockAndWaitItOut( $service );
		self::assertSame( 24 * 60, $service->loginNextLockMinutes( self::IP, self::USER ) );
	}

	public function test_clear_resets_counter_lock_and_step(): void {
		$service = $this->makeService();

		$this->lockAndWaitItOut( $service );
		$this->failLogin( $service, 3 );
		self::assertTrue( $service->isLoginLocked( self::IP, self::USER ) );

		$service->clearLoginFailures( self::IP, self::USER );

		self::assertFalse( $service->isLoginLocked( self::IP, self::USER ) );
		self::assertSame( RateLimitService::LIMIT_LOGIN, $service->loginAttemptsLeft( self::IP, self::USER ) );
		self::assertSame( 15, $service->loginNextLockMinutes( self::IP, self::USER ), 'успешный вход возвращает первую ступень' );
	}

	public function test_retry_after_is_rounded_up_minutes_and_zero_when_open(): void {
		$service = $this->makeService();

		$this->failLogin( $service );
		self::assertSame( 0, $service->loginRetryAfter( self::IP, self::USER ), 'вход открыт — ждать нечего' );

		$this->failLogin( $service, 2 );
		$this->ageLogin( $service, 14 * MINUTE_IN_SECONDS + 30 );
		self::assertSame( 1, $service->loginRetryAfter( self::IP, self::USER ), '30 секунд округляются до минуты' );
	}

	public function test_counter_in_previous_format_is_read_as_first_step(): void {
		$service = $this->makeService();
		$GLOBALS['_test_transients'][ $service->loginKey( self::IP, self::USER ) ] = array( 'count' => 2, 'reset_at' => time() + 120 );

		self::assertSame( 1, $service->loginAttemptsLeft( self::IP, self::USER ) );
		self::assertFalse( $service->isLoginLocked( self::IP, self::USER ) );
		self::assertSame( 15, $service->loginNextLockMinutes( self::IP, self::USER ) );
	}

	public function test_retry_seconds_count_down_the_lock(): void {
		$service = $this->makeService();

		self::assertSame( 0, $service->loginRetrySeconds( self::IP, self::USER ) );

		$this->failLogin( $service, 3 );
		self::assertEqualsWithDelta( 15 * MINUTE_IN_SECONDS, $service->loginRetrySeconds( self::IP, self::USER ), 2 );

		$this->ageLogin( $service, 10 * MINUTE_IN_SECONDS );
		self::assertEqualsWithDelta( 5 * MINUTE_IN_SECONDS, $service->loginRetrySeconds( self::IP, self::USER ), 2 );
	}

	// ── Сброс блокировки администратором ─────────────────────────────────────────

	public function test_admin_reset_unlocks_pair_from_every_address(): void {
		$service = $this->makeService();

		$service->registerLoginFailure( '1.1.1.1', self::USER );
		$service->registerLoginFailure( '1.1.1.1', self::USER );
		$service->registerLoginFailure( '1.1.1.1', self::USER );
		for ( $i = 0; $i < 3; $i++ ) {
			$service->registerLoginFailure( '2.2.2.2', self::USER );
		}
		self::assertTrue( $service->isLoginLocked( '1.1.1.1', self::USER ) );
		self::assertTrue( $service->isLoginLocked( '2.2.2.2', self::USER ) );

		$service->resetLoginLock( self::USER );

		foreach ( array( '1.1.1.1', '2.2.2.2' ) as $ip ) {
			self::assertFalse( $service->isLoginLocked( $ip, self::USER ) );
			self::assertSame( 0, $service->loginRetrySeconds( $ip, self::USER ) );
			self::assertSame( RateLimitService::LIMIT_LOGIN, $service->loginAttemptsLeft( $ip, self::USER ) );
		}
	}

	public function test_admin_reset_returns_first_step(): void {
		$service = $this->makeService();

		$this->lockAndWaitItOut( $service );
		self::assertSame( 60, $service->loginNextLockMinutes( self::IP, self::USER ) );

		$service->resetLoginLock( self::USER );

		self::assertSame( 15, $service->loginNextLockMinutes( self::IP, self::USER ) );
	}

	public function test_admin_reset_does_not_touch_other_users(): void {
		$service = $this->makeService();

		$this->failLogin( $service, 3 );
		$service->resetLoginLock( 'id:8' );

		self::assertTrue( $service->isLoginLocked( self::IP, self::USER ) );
	}

	public function test_failures_after_admin_reset_count_again(): void {
		$service = $this->makeService();

		$this->failLogin( $service, 3 );
		// Прежние неудачи были давно, а сброс — секунду назад: новые неудачи позже метки и считаются.
		$GLOBALS['_test_transients'][ $service->loginKey( self::IP, self::USER ) ]['touched_at'] -= 10;
		$service->resetLoginLock( self::USER );
		$GLOBALS['_test_transients'][ $service->loginResetKey( self::USER ) ] = microtime( true ) - 1;

		self::assertSame( 2, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertSame( 1, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertSame( 0, $service->registerLoginFailure( self::IP, self::USER ) );
		self::assertSame( 15, $service->loginRetryAfter( self::IP, self::USER ), 'после сброса ступень снова первая' );
	}

	public function test_test_env_does_not_disable_login_limit(): void {
		$service = $this->makeService( testEnv: true );

		$this->failLogin( $service, RateLimitService::LIMIT_LOGIN );

		self::assertTrue( $service->isLoginLocked( self::IP, self::USER ) );
	}

	// ── Экзамены: ключ приглашения и брони (11a.2.5) ──────────────────────────────

	public function test_invitation_lock_after_twenty_failures(): void {
		$service = $this->makeService();
		$ip      = '5.5.5.5';

		for ( $i = 1; $i <= 19; $i++ ) {
			$service->registerInvitationFailure( $ip );
			self::assertFalse( $service->isInvitationLocked( $ip ), "После $i неудач ещё открыто" );
		}
		$service->registerInvitationFailure( $ip );

		self::assertTrue( $service->isInvitationLocked( $ip ) );
		self::assertFalse( $service->isInvitationLocked( '6.6.6.6' ), 'Чужой адрес не заперт.' );
	}

	public function test_successful_openings_are_not_counted(): void {
		$service = $this->makeService();

		// Проверка блокировки счётчик не увеличивает: сколько ни проверяй, адрес открыт.
		for ( $i = 0; $i < 100; $i++ ) {
			self::assertFalse( $service->isInvitationLocked( '5.5.5.5' ) );
		}
	}

	public function test_exam_hold_hourly_limit_and_trusted_multiplier(): void {
		$plain   = $this->makeService();
		$trusted = $this->makeService( false, array( '9.9.9.9' ) );

		self::assertSame( 5, $this->passesBeforeBlock( fn() => $plain->allowExamHoldCreation( '5.5.5.5', 5 ) ) );
		self::assertSame( 50, $this->passesBeforeBlock( fn() => $trusted->allowExamHoldCreation( '9.9.9.9', 5 ) ) );
	}

	public function test_ip_hash_is_salted_and_stable(): void {
		$service = $this->makeService();

		self::assertSame( $service->ipHash( '5.5.5.5' ), $service->ipHash( '5.5.5.5' ) );
		self::assertNotSame( $service->ipHash( '5.5.5.5' ), hash( 'sha256', '5.5.5.5' ) );
		self::assertSame( 64, strlen( $service->ipHash( '5.5.5.5' ) ) );
	}
}
