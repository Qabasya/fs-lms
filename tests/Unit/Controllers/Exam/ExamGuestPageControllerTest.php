<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Controllers\Exam\ExamGuestPageController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Exam\GuestPageResponder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Политика robots/canonical закрывает гостевые страницы и сохраняет настройки других страниц. */
#[AllowMockObjectsWithoutExpectations]
class ExamGuestPageControllerTest extends TestCase {

	private ExamGuestPageController $controller;
	private \Inc\Services\Exam\GuestPageAccessService&\PHPUnit\Framework\MockObject\MockObject $access;
	private GuestPageResponder&\PHPUnit\Framework\MockObject\MockObject $responder;

	protected function setUp(): void {
		$_GET = $_COOKIE = array();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
		$this->access = $this->createMock( \Inc\Services\Exam\GuestPageAccessService::class );
		$this->responder = $this->createMock( GuestPageResponder::class );
		$this->controller = new \Inc\Controllers\Exam\ExamGuestPageController(
			$this->access,
			$this->createMock( \Inc\Services\Exam\GuestSessionService::class ),
			$this->createMock( \Inc\Services\Exam\GuestSignupViewService::class ),
			$this->createMock( \Inc\Services\Exam\GuestEntryViewService::class ),
			$this->createMock( \Inc\Services\Exam\GuestResultViewService::class ),
			$this->createMock( \Inc\Services\Exam\GuestResultSidebarService::class ),
			$this->createMock( \Inc\Services\Exam\ExamReportViewService::class ),
			$this->responder,
			$this->createMock( \Inc\Managers\Wp\PostManager::class )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_page_slug'] );
		$_GET = $_COOKIE = array();
		parent::tearDown();
	}

	/** @return array<string, array{PageRoutes}> */
	public static function guestRoutes(): array {
		return array(
			'signup' => array( PageRoutes::ExamSignup ),
			'entry'  => array( PageRoutes::ExamEntry ),
			'result' => array( PageRoutes::ExamResult ),
			'report' => array( PageRoutes::ExamReport ),
		);
	}

	#[DataProvider( 'guestRoutes' )]
	public function test_guest_page_is_not_indexable_and_has_no_canonical( PageRoutes $route ): void {
		$GLOBALS['_fs_test_page_slug'] = $route->value;

		$robots = $this->controller->filterRobots( array( 'index' => true, 'follow' => true, 'max-image-preview' => 'large' ) );

		self::assertTrue( $robots['noindex'] );
		self::assertTrue( $robots['nofollow'] );
		self::assertArrayNotHasKey( 'index', $robots );
		self::assertArrayNotHasKey( 'follow', $robots );
		self::assertSame( 'large', $robots['max-image-preview'] );
		self::assertFalse( $this->controller->filterCanonicalUrl( $route->url() ) );
	}

	public function test_other_pages_keep_robots_and_canonical(): void {
		$GLOBALS['_fs_test_page_slug'] = PageRoutes::SignIn->value;
		$robots = array( 'index' => true, 'follow' => true );

		self::assertSame( $robots, $this->controller->filterRobots( $robots ) );
		self::assertSame( 'https://example.test/sign-in/', $this->controller->filterCanonicalUrl( 'https://example.test/sign-in/' ) );
		self::assertFalse( $this->controller->filterCanonicalUrl( false ) );
	}
	#[DataProvider( 'guestRoutes' )]
	public function test_http_adapter_passes_normalized_credentials_and_applies_outcome( PageRoutes $route ): void {
		$GLOBALS['_fs_test_page_slug'] = $route->value;
		$_GET = array( 'k' => ' ABC123 ', 'pay' => ' DEF456 ' );
		$_COOKIE[ \Inc\Services\Exam\GuestSessionService::COOKIE_INVITATION ] = 'INVITE';
		$outcome = \Inc\DTO\Exam\GuestPageOutcome::notFound();
		[$handler, $policy] = $this->handlers( $route );
		$arguments = PageRoutes::ExamSignup === $route ? array( 'abc123', 'def456', 'invite', '10.0.0.7' ) : array( 'abc123', '10.0.0.7' );
		$this->access->expects( self::once() )->method( $policy )->with( ...$arguments )->willReturn( $outcome );
		$this->responder->expects( self::once() )->method( 'sendHeaders' );
		$this->responder->expects( self::once() )->method( 'apply' )->with( self::identicalTo( $outcome ) );

		$this->controller->$handler();
	}

	#[DataProvider( 'guestRoutes' )]
	public function test_http_adapter_ignores_other_pages( PageRoutes $route ): void {
		$GLOBALS['_fs_test_page_slug'] = PageRoutes::SignIn->value;
		[$handler, $policy] = $this->handlers( $route );
		$this->access->expects( self::never() )->method( $policy );
		$this->responder->expects( self::never() )->method( 'sendHeaders' );
		$this->responder->expects( self::never() )->method( 'apply' );

		$this->controller->$handler();
	}

	public function test_malformed_credentials_are_passed_as_empty_strings(): void {
		$GLOBALS['_fs_test_page_slug'] = PageRoutes::ExamSignup->value;
		$_GET = array( 'k' => array( 'invalid' ), 'pay' => array( 'invalid' ) );
		$_COOKIE[ \Inc\Services\Exam\GuestSessionService::COOKIE_INVITATION ] = array( 'invalid' );
		$this->access->expects( self::once() )->method( 'invitationPage' )->with( '', '', '', '10.0.0.7' )->willReturn( \Inc\DTO\Exam\GuestPageOutcome::notFound() );

		$this->controller->handleSignupPage();
	}

	/** @return array{string, string} */
	private function handlers( PageRoutes $route ): array {
		return match ( $route ) {
			PageRoutes::ExamSignup => array( 'handleSignupPage', 'invitationPage' ),
			PageRoutes::ExamEntry => array( 'handleEntryPage', 'entryPage' ),
			PageRoutes::ExamResult => array( 'handleResultPage', 'resultPage' ),
			PageRoutes::ExamReport => array( 'handleReportPage', 'reportPage' ),
			default => throw new \InvalidArgumentException( 'Not a guest route' ),
		};
	}

}
