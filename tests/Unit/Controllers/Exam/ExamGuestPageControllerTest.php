<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Callbacks\Exam\ExamReportPageCallbacks;
use Inc\Callbacks\Exam\GuestApplicationCallbacks;
use Inc\Callbacks\Exam\GuestEntryCallbacks;
use Inc\Controllers\Exam\ExamGuestPageController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Services\Exam\GuestPageResponder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Политика robots/canonical закрывает гостевые страницы и сохраняет настройки других страниц. */
#[AllowMockObjectsWithoutExpectations]
class ExamGuestPageControllerTest extends TestCase {

	private ExamGuestPageController $controller;

	protected function setUp(): void {
		$this->controller = new ExamGuestPageController(
			$this->createMock( GuestApplicationCallbacks::class ),
			$this->createMock( GuestEntryCallbacks::class ),
			$this->createMock( ExamReportPageCallbacks::class ),
			$this->createMock( GuestPageResponder::class ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_page_slug'] );
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
}
