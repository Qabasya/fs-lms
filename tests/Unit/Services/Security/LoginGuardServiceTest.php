<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use Inc\Enums\Auth\LoginNotice;
use Inc\Managers\Person\UserManager;
use Inc\Services\Captcha\CaptchaService;
use Inc\Services\Security\LoginGuardService;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_User;

class LoginGuardServiceTest extends TestCase {

	private const IP = '10.0.0.1';

	private RateLimitService $rateLimit;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_transients'] = array();
	}

	private function user( int $id ): WP_User {
		$user     = new WP_User();
		$user->ID = $id;
		return $user;
	}

	/**
	 * @param array<string, WP_User> $logins Логин → пользователь
	 * @param array<string, WP_User> $emails Email → пользователь
	 */
	private function makeService(
		bool $captchaConfigured = false,
		bool $captchaValid = true,
		bool $testEnv = false,
		array $logins = array(),
		array $emails = array(),
	): LoginGuardService {
		$config = $this->createStub( PluginConfig::class );
		$config->method( 'isTestEnv' )->willReturn( $testEnv );

		$this->rateLimit = new RateLimitService( $config );

		$captcha = $this->createStub( CaptchaService::class );
		$captcha->method( 'isConfigured' )->willReturn( $captchaConfigured );
		$captcha->method( 'validate' )->willReturn( $captchaValid );

		$users = $this->createStub( UserManager::class );
		$users->method( 'findByLogin' )->willReturnCallback( static fn ( string $l ): ?WP_User => $logins[ $l ] ?? null );
		$users->method( 'findByEmail' )->willReturnCallback( static fn ( string $e ): ?WP_User => $emails[ $e ] ?? null );

		return new LoginGuardService( $this->rateLimit, $captcha, $users, $config );
	}

	private function failTimes( LoginGuardService $service, string $login, int $times ): void {
		for ( $i = 0; $i < $times; $i++ ) {
			$service->onFailure( $login, new WP_Error( 'incorrect_password', 'x' ), self::IP );
		}
	}

	// ── Лимит ───────────────────────────────────────────────────────────────────

	public function test_locked_pair_is_rejected_even_with_correct_password(): void {
		$service = $this->makeService( logins: array( 'ivan' => $this->user( 5 ) ) );
		$this->failTimes( $service, 'ivan', 3 );

		$result = $service->guard( $this->user( 5 ), 'ivan', '', self::IP, true );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( LoginGuardService::CODE_LOCKED, $result->get_error_code() );
	}

	public function test_guard_rejections_are_not_counted(): void {
		$service = $this->makeService();

		$service->onFailure( 'ivan', new WP_Error( LoginGuardService::CODE_LOCKED, 'x' ), self::IP );
		$service->onFailure( 'ivan', new WP_Error( LoginGuardService::CODE_CAPTCHA, 'x' ), self::IP );

		self::assertSame( 3, $this->rateLimit->loginAttemptsLeft( self::IP, $service->userKey( 'ivan' ) ) );
	}

	public function test_login_and_email_of_same_user_share_key(): void {
		$user    = $this->user( 5 );
		$service = $this->makeService( logins: array( 'ivan' => $user ), emails: array( 'ivan@test.ru' => $user ) );

		self::assertSame( $service->userKey( 'ivan' ), $service->userKey( 'ivan@test.ru' ) );

		$this->failTimes( $service, 'ivan', 2 );
		$this->failTimes( $service, 'ivan@test.ru', 1 );

		self::assertTrue( $this->rateLimit->isLoginLocked( self::IP, 'id:5' ) );
	}

	public function test_unknown_login_is_normalized(): void {
		$service = $this->makeService();

		self::assertSame( $service->userKey( '  Ghost ' ), $service->userKey( 'ghost' ) );
	}

	public function test_success_clears_counter(): void {
		$user    = $this->user( 5 );
		$service = $this->makeService( logins: array( 'ivan' => $user ) );
		$this->failTimes( $service, 'ivan', 2 );

		$service->onSuccess( $user, self::IP );

		self::assertSame( 3, $this->rateLimit->loginAttemptsLeft( self::IP, 'id:5' ) );
	}

	// ── Уведомления ─────────────────────────────────────────────────────────────

	public function test_notice_after_first_failure_is_failed(): void {
		$service = $this->makeService();
		$this->failTimes( $service, 'ivan', 1 );

		self::assertSame( LoginNotice::Failed, $service->noticeFor( 'ivan', null, self::IP )->notice );
	}

	public function test_notice_after_second_failure_is_last(): void {
		$service = $this->makeService();
		$this->failTimes( $service, 'ivan', 2 );

		self::assertSame( LoginNotice::Last, $service->noticeFor( 'ivan', null, self::IP )->notice );
	}

	public function test_notice_after_third_failure_is_locked_with_wait(): void {
		$service = $this->makeService();
		$this->failTimes( $service, 'ivan', 3 );

		$notice = $service->noticeFor( 'ivan', null, self::IP );

		self::assertSame( LoginNotice::Locked, $notice->notice );
		self::assertSame( 15, $notice->waitMinutes );
		self::assertSame( array( 'login' => 'locked', 'wait' => 15 ), $notice->queryArgs() );
	}

	public function test_notice_for_captcha_error(): void {
		$service = $this->makeService();

		$notice = $service->noticeFor( 'ivan', new WP_Error( LoginGuardService::CODE_CAPTCHA, 'x' ), self::IP );

		self::assertSame( LoginNotice::Captcha, $notice->notice );
	}

	// ── Капча ───────────────────────────────────────────────────────────────────

	public function test_captcha_required_on_login_form_when_configured(): void {
		$service = $this->makeService( captchaConfigured: true, captchaValid: false );

		$result = $service->guard( null, 'ivan', '', self::IP, true );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( LoginGuardService::CODE_CAPTCHA, $result->get_error_code() );
	}

	public function test_captcha_not_required_when_not_configured(): void {
		$service = $this->makeService( captchaConfigured: false, captchaValid: false );
		$user    = $this->user( 5 );

		self::assertSame( $user, $service->guard( $user, 'ivan', '', self::IP, true ) );
	}

	public function test_captcha_skipped_outside_login_form_and_in_test_env(): void {
		$user = $this->user( 5 );

		$service = $this->makeService( captchaConfigured: true, captchaValid: false );
		self::assertSame( $user, $service->guard( $user, 'ivan', '', self::IP, false ) );

		$service = $this->makeService( captchaConfigured: true, captchaValid: false, testEnv: true );
		self::assertSame( $user, $service->guard( $user, 'ivan', '', self::IP, true ) );
	}

	public function test_locked_pair_is_rejected_before_captcha(): void {
		$service = $this->makeService( captchaConfigured: true, captchaValid: false );
		$this->failTimes( $service, 'ivan', 3 );

		$result = $service->guard( null, 'ivan', '', self::IP, true );

		self::assertSame( LoginGuardService::CODE_LOCKED, $result->get_error_code() );
	}

	public function test_empty_login_passes_through(): void {
		$service = $this->makeService( captchaConfigured: true, captchaValid: false );
		$error   = new WP_Error( 'empty_username', 'x' );

		self::assertSame( $error, $service->guard( $error, '', '', self::IP, true ) );
	}
}
