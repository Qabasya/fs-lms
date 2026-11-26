<?php

declare( strict_types=1 );

namespace Unit\Controllers\Person;

use Inc\Controllers\Person\LmsSessionController;
use Inc\Repositories\WPDBRepositories\UserSessionRepository;
use Inc\Services\Security\LmsSessionService;
use PHPUnit\Framework\TestCase;

class LmsSessionControllerTest extends TestCase {

	public function test_only_lms_form_login_marks_a_persistent_session_for_ninety_days(): void {
		$controller = new LmsSessionController( $this->createStub( LmsSessionService::class ) );
		$server = $_SERVER;
		$post = $_POST;
		$previousPage = $GLOBALS['pagenow'] ?? null;
		try {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST = array( 'fs_lms_login' => '1', 'rememberme' => 'forever' );
			$GLOBALS['pagenow'] = 'wp-login.php';

			self::assertSame( 90 * DAY_IN_SECONDS, $controller->loginLifetime( 14 * DAY_IN_SECONDS, 7, true ) );
			self::assertTrue( $controller->tagSession( array() )[ UserSessionRepository::LMS_SESSION ] );

			unset( $_POST['fs_lms_login'] );
			self::assertSame( 14 * DAY_IN_SECONDS, $controller->loginLifetime( 14 * DAY_IN_SECONDS, 7, true ) );
			self::assertSame( array(), $controller->tagSession( array() ) );
		} finally {
			$_SERVER = $server;
			$_POST = $post;
			$GLOBALS['pagenow'] = $previousPage;
		}
	}

	public function test_page_navigation_renews_the_session_but_background_requests_do_not(): void {
		$service = $this->createMock( LmsSessionService::class );
		$service->expects( self::exactly( 3 ) )->method( 'renewCurrent' )->with( 7 );
		$controller = new LmsSessionController( $service );
		$server = $_SERVER;
		$userId = $GLOBALS['_fs_test_user_id'] ?? null;
		$page = $GLOBALS['_fs_test_page_slug'] ?? null;
		try {
			$GLOBALS['_fs_test_user_id'] = 7;
			$GLOBALS['_fs_test_page_slug'] = 'profile';
			$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document' );
			$controller->renew();
			$GLOBALS['_fs_test_page_slug'] = 'lesson';
			$controller->renew();
			$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
			$controller->renew();
			unset( $_SERVER['HTTP_SEC_PURPOSE'] );
			$_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';
			$controller->renew();
			$_SERVER = array( 'REQUEST_METHOD' => 'POST' );
			$controller->renew();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$GLOBALS['_fs_test_page_slug'] = 'sign-in';
			$controller->renew();
		} finally {
			$_SERVER = $server;
			$GLOBALS['_fs_test_user_id'] = $userId;
			$GLOBALS['_fs_test_page_slug'] = $page;
		}
	}
}
