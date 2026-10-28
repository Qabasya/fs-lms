<?php

declare( strict_types=1 );

namespace Unit\Controllers\Person;

use Inc\Controllers\Person\AuthPageController;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Services\Security\LoginGuardService;
use PHPUnit\Framework\TestCase;

/** Страница входа: сообщение и блокировка полей строятся по настоящему состоянию блокировки, а не по флагу в адресе. */
class AuthPageControllerTest extends TestCase {

	private LoginGuardService&\PHPUnit\Framework\MockObject\MockObject $guard;
	private AuthPageController $controller;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_logged_in'] = false;
		$_GET                       = array();
		$_SERVER['REMOTE_ADDR']     = '10.0.0.1';

		$consents = $this->createStub( ConsentDefinitionsRepository::class );
		$consents->method( 'findByKey' )->willReturn( null );

		$this->guard      = $this->createMock( LoginGuardService::class );
		$this->controller = new AuthPageController( $consents, $this->guard );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_logged_in'] );
		$_GET = array();
		parent::tearDown();
	}

	public function test_locked_login_disables_form_and_leaves_the_time_to_the_timer(): void {
		$_GET = array( 'fs_user' => 'ivan' );
		$this->guard->expects( $this->once() )->method( 'lockSecondsFor' )->with( 'ivan', '10.0.0.1' )->willReturn( 3700 );

		$html = $this->controller->renderLoginPage();

		self::assertStringContainsString( 'data-login-lock="3700"', $html );
		self::assertMatchesRegularExpression( '~fs-auth-card__error"[^>]*>\s*Слишком много неудачных попыток\.\s*</div>~u', $html, 'плашка называет причину и не дублирует время' );
		self::assertStringNotContainsString( 'Повторите вход', $html );
		self::assertStringNotContainsString( 'через 1 ч.', $html );
	}

	public function test_lock_is_shown_even_without_the_flag_in_the_address(): void {
		$_GET = array( 'fs_user' => 'ivan' );
		$this->guard->method( 'lockSecondsFor' )->willReturn( 600 );

		$html = $this->controller->renderLoginPage();

		self::assertStringContainsString( 'Слишком много неудачных попыток', $html );
		self::assertStringContainsString( 'data-login-lock="600"', $html );
	}

	public function test_expired_lock_flag_in_address_shows_neither_message_nor_lock(): void {
		$_GET = array( 'login' => 'locked', 'wait' => '15', 'fs_user' => 'ivan' );
		$this->guard->method( 'lockSecondsFor' )->willReturn( 0 );

		$html = $this->controller->renderLoginPage();

		self::assertStringNotContainsString( 'Слишком много неудачных попыток', $html );
		self::assertStringNotContainsString( 'data-login-lock', $html );
		self::assertSame( 0, preg_match( '/<input[^>]*name="pwd"[^>]*disabled/', $html ), 'форма снова открыта' );
	}

	public function test_lock_flag_without_login_falls_back_to_address_wait(): void {
		$_GET = array( 'login' => 'locked', 'wait' => '15' );
		$this->guard->method( 'lockSecondsFor' )->with( '', '10.0.0.1' )->willReturn( 0 );

		self::assertStringContainsString( 'через 15 мин.', $this->controller->renderLoginPage() );
	}

	public function test_other_notices_are_shown_as_before(): void {
		$_GET = array( 'login' => 'failed', 'fs_user' => 'ivan' );
		$this->guard->method( 'lockSecondsFor' )->willReturn( 0 );

		self::assertStringContainsString( 'Неверный логин или пароль.', $this->controller->renderLoginPage() );
	}

	public function test_wait_in_address_is_capped_to_the_longest_lock(): void {
		$_GET = array( 'login' => 'last', 'wait' => '999999' );
		$this->guard->method( 'lockSecondsFor' )->willReturn( 0 );

		self::assertStringContainsString( 'на 24 ч.', $this->controller->renderLoginPage() );
	}
}
