<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Enums\Auth\CaptchaScope;
use Inc\Modules\Masterclass\Controllers\MasterclassAuthController;
use Inc\Modules\Masterclass\Enums\LoginPlace;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassLoginTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Вход временного ученика: редиректы сразу в плеер (только живой аккаунт), `wp_login` → трекер, обход капчи (Ш6) только для входа.
 */
class MasterclassAuthControllerTest extends TestCase {

	private const PLAYER = 'http://example.com/lesson/?gid=31&gl=41';

	private MasterclassAccountService&MockObject $accounts;
	private MasterclassLoginTracker&MockObject $tracker;
	private MasterclassAuthController $controller;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_logged_in']     = true;
		$GLOBALS['_fs_test_user_id']    = 105;
		$GLOBALS['_fs_test_page_slug']  = 'sign-in';

		$this->accounts   = $this->createMock( MasterclassAccountService::class );
		$this->tracker    = $this->createMock( MasterclassLoginTracker::class );
		$this->controller = new MasterclassAuthController( $this->accounts, $this->tracker );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_logged_in'], $GLOBALS['_fs_test_user_id'], $GLOBALS['_fs_test_page_slug'] );
		parent::tearDown();
	}

	private function user( int $id = 105 ): \WP_User {
		$user     = new \WP_User();
		$user->ID = $id;

		return $user;
	}

	public function test_account_on_sign_in_is_sent_to_the_player(): void {
		$this->accounts->method( 'playerUrlFor' )->with( 105 )->willReturn( self::PLAYER );

		self::assertSame( self::PLAYER, $this->controller->accountRedirectUrl() );
	}

	public function test_account_on_the_profile_is_sent_to_the_player(): void {
		$GLOBALS['_fs_test_page_slug'] = 'profile';
		$this->accounts->method( 'playerUrlFor' )->willReturn( self::PLAYER );

		self::assertSame( self::PLAYER, $this->controller->accountRedirectUrl() );
	}

	public function test_other_pages_are_not_redirected(): void {
		$GLOBALS['_fs_test_page_slug'] = 'apply';
		$this->accounts->expects( self::never() )->method( 'playerUrlFor' );

		self::assertNull( $this->controller->accountRedirectUrl() );
	}

	public function test_a_guest_is_not_redirected(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->accounts->expects( self::never() )->method( 'playerUrlFor' );

		self::assertNull( $this->controller->accountRedirectUrl() );
	}

	public function test_a_regular_user_keeps_the_usual_behaviour(): void {
		$this->accounts->method( 'playerUrlFor' )->willReturn( null );

		self::assertNull( $this->controller->accountRedirectUrl() );
	}

	public function test_login_redirect_leads_an_account_to_the_player(): void {
		$this->accounts->method( 'playerUrlFor' )->with( 105 )->willReturn( self::PLAYER );

		self::assertSame( self::PLAYER, $this->controller->filterLoginRedirect( '/profile/', '', $this->user() ) );
	}

	public function test_login_redirect_leaves_a_regular_user_alone(): void {
		$this->accounts->method( 'playerUrlFor' )->willReturn( null );

		self::assertSame( '/profile/', $this->controller->filterLoginRedirect( '/profile/', '', $this->user( 7 ) ) );
	}

	public function test_login_redirect_ignores_a_failed_login(): void {
		$this->accounts->expects( self::never() )->method( 'playerUrlFor' );

		self::assertSame( '/profile/', $this->controller->filterLoginRedirect( '/profile/', '', new \WP_Error() ) );
	}

	public function test_login_goes_to_the_tracker_with_the_user(): void {
		$user = $this->user();
		$this->tracker->expects( self::once() )->method( 'onLogin' )->with( $user, self::isType( 'string' ) )->willReturn( LoginPlace::OnSite );

		$this->controller->onLogin( 'student-5', $user );
	}

	public function test_a_tracker_failure_never_breaks_the_login(): void {
		$this->tracker->method( 'onLogin' )->willThrowException( new \RuntimeException( 'db down' ) );

		$this->controller->onLogin( 'student-5', $this->user() );

		$this->addToAssertionCount( 1 );
	}

	public function test_bypass_is_asked_only_for_the_login_scope(): void {
		$this->tracker->expects( self::once() )->method( 'allowsCaptchaBypass' )->with( 'student-5' )->willReturn( true );

		self::assertTrue( $this->controller->filterCaptchaBypass( false, CaptchaScope::Login, 'student-5', '1.2.3.4' ) );
		self::assertFalse( $this->controller->filterCaptchaBypass( false, CaptchaScope::Apply, 'student-5', '1.2.3.4' ) );
	}

	public function test_bypass_is_refused_when_the_tracker_refuses(): void {
		$this->tracker->method( 'allowsCaptchaBypass' )->willReturn( false );

		self::assertFalse( $this->controller->filterCaptchaBypass( false, CaptchaScope::Login, 'ivanov', '1.2.3.4' ) );
	}

	public function test_bypass_keeps_a_decision_made_by_another_subscriber(): void {
		$this->tracker->method( 'allowsCaptchaBypass' )->willReturn( false );

		self::assertTrue( $this->controller->filterCaptchaBypass( true, CaptchaScope::Login, 'x', '1.2.3.4' ) );
	}

	public function test_a_garbage_scope_is_refused(): void {
		$this->tracker->expects( self::never() )->method( 'allowsCaptchaBypass' );

		self::assertFalse( $this->controller->filterCaptchaBypass( false, 'login', 'student-5', '1.2.3.4' ) );
	}
}
