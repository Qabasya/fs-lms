<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Controllers\Exam\ExamGuestPageController;
use Inc\Enums\Wp\PageRoutes;
use Inc\Services\Exam\GuestResultViewService;
use Inc\Services\Exam\GuestSessionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** ФИО гостя — в заголовке страницы результата (рядом с названием), а не в хлебной крошке и не во вкладке браузера. */
#[AllowMockObjectsWithoutExpectations]
class ExamGuestResultTitleTest extends TestCase {

	private ExamGuestPageController $controller;
	private GuestSessionService&MockObject $sessions;
	private GuestResultViewService&MockObject $names;

	protected function setUp(): void {
		parent::setUp();
		$this->sessions = $this->createMock( GuestSessionService::class );
		$this->names    = $this->createMock( GuestResultViewService::class );
		$this->controller = new ExamGuestPageController(
			$this->createMock( \Inc\Services\Exam\GuestPageAccessService::class ),
			$this->sessions,
			$this->createMock( \Inc\Services\Exam\GuestSignupViewService::class ),
			$this->createMock( \Inc\Services\Exam\GuestEntryViewService::class ),
			$this->names,
			$this->createMock( \Inc\Services\Exam\GuestResultSidebarService::class ),
			$this->createMock( \Inc\Services\Exam\ExamReportViewService::class ),
			$this->createMock( \Inc\Services\Exam\GuestPageResponder::class ),
			$this->createMock( \Inc\Managers\Wp\PostManager::class )
		);
		$GLOBALS['_fs_test_page_slug']  = PageRoutes::ExamResult->value;
		$GLOBALS['_fs_test_in_loop']    = true;
		$GLOBALS['_fs_test_main_query'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_page_slug'], $GLOBALS['_fs_test_in_loop'], $GLOBALS['_fs_test_main_query'] );
		parent::tearDown();
	}

	public function test_guest_name_is_appended_to_the_page_title(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->names->method( 'guestName' )->with( 4 )->willReturn( 'Иванов Пётр' );

		self::assertSame( 'Результат экзамена: Иванов Пётр', $this->controller->filterResultTitle( 'Результат экзамена' ) );
	}

	public function test_title_is_untouched_without_guest_session_or_name(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( null );
		self::assertSame( 'Результат экзамена', $this->controller->filterResultTitle( 'Результат экзамена' ) );
	}

	public function test_title_is_untouched_when_guest_has_no_name(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->names->method( 'guestName' )->willReturn( '' );

		self::assertSame( 'Результат экзамена', $this->controller->filterResultTitle( 'Результат экзамена' ) );
	}

	/** Вкладка браузера (вне цикла), меню и чужие страницы остаются как были — ФИО там не светим. */
	public function test_title_outside_main_loop_and_on_other_pages_is_untouched(): void {
		$this->sessions->method( 'viewableParticipationId' )->willReturn( 4 );
		$this->names->method( 'guestName' )->willReturn( 'Иванов Пётр' );

		$GLOBALS['_fs_test_in_loop'] = false;
		self::assertSame( 'Результат экзамена', $this->controller->filterResultTitle( 'Результат экзамена' ) );

		$GLOBALS['_fs_test_in_loop']   = true;
		$GLOBALS['_fs_test_page_slug'] = PageRoutes::SignIn->value;
		self::assertSame( 'Вход', $this->controller->filterResultTitle( 'Вход' ) );
	}
}
