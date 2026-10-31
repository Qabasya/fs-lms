<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Managers\Person\UserManager;
use Inc\Services\Profile\HomeLayoutService;
use Inc\Enums\Access\UserRole;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Раскладка главной ученика/родителя («Кастомизация»): порядок трёх секций и набор плиток (до шести), на пользователя.
 * Из браузера приходит что угодно — сохраняется только проверенное по белому списку.
 */
class HomeLayoutServiceTest extends TestCase {

	private UserManager&MockObject $users;
	private HomeLayoutService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->users   = $this->createMock( UserManager::class );
		$this->service = new HomeLayoutService( $this->users );
	}

	public function test_default_layout_has_all_sections_and_automatic_tiles(): void {
		self::assertSame( array( 'sections' => array( 'tiles', 'schedule', 'deadlines' ), 'tiles' => null ), $this->service->defaultLayout() );
	}

	public function test_valid_layout_is_kept(): void {
		$layout = array( 'sections' => array( 'deadlines', 'tiles', 'schedule' ), 'tiles' => array( 'exam:inf_ege', 'deadlines', 'streak' ) );

		self::assertSame( $layout, $this->service->normalize( $layout ) );
	}

	/** Секции — ровно перестановка трёх известных: дубли, пропуски и чужие id возвращают порядок по умолчанию. */
	public function test_broken_sections_fall_back_to_default_order(): void {
		foreach ( array(
			array( 'tiles', 'tiles', 'schedule' ),
			array( 'tiles', 'schedule' ),
			array( 'tiles', 'schedule', 'deadlines', 'evil' ),
			'tiles',
			null,
		) as $sections ) {
			self::assertSame( array( 'tiles', 'schedule', 'deadlines' ), $this->service->normalize( array( 'sections' => $sections ) )['sections'] );
		}
	}

	public function test_tiles_are_filtered_by_whitelist_deduplicated_and_limited_to_six(): void {
		$tiles = array( 'deadlines', 'deadlines', 'attendance', 'bogus', '<script>', 'exam:inf_ege', 'exam:Bad Key', 'streak', 'to_fix', 'last_result', 'exam:python' );

		self::assertSame(
			array( 'deadlines', 'attendance', 'exam:inf_ege', 'streak', 'to_fix', 'last_result' ),
			$this->service->normalize( array( 'tiles' => $tiles ) )['tiles']
		);
	}

	/** Не прислано и мусор — «плитки по умолчанию»; пустой список — осознанный выбор (все убраны, остался «+»). */
	public function test_missing_or_junk_tiles_mean_default_and_empty_list_is_kept(): void {
		self::assertNull( $this->service->normalize( array() )['tiles'] );
		self::assertNull( $this->service->normalize( array( 'tiles' => 'streak' ) )['tiles'] );
		self::assertSame( array(), $this->service->normalize( array( 'tiles' => array() ) )['tiles'] );
		self::assertSame( array( 'sections' => array( 'tiles', 'schedule', 'deadlines' ), 'tiles' => null ), $this->service->normalize( 'мусор' ) );
	}

	public function test_get_returns_normalized_stored_layout_or_default(): void {
		$this->users->method( 'homeLayout' )->willReturnOnConsecutiveCalls( array(), array( 'sections' => array( 'schedule', 'deadlines', 'tiles' ), 'tiles' => array( 'streak' ) ) );

		self::assertSame( $this->service->defaultLayout(), $this->service->get( 5 ) );
		self::assertSame( array( 'sections' => array( 'schedule', 'deadlines', 'tiles' ), 'tiles' => array( 'streak' ) ), $this->service->get( 5 ) );
	}

	public function test_save_stores_only_the_normalized_layout_and_returns_it(): void {
		$this->users->expects( self::once() )->method( 'setHomeLayout' )->with( 5, array( 'sections' => array( 'tiles', 'schedule', 'deadlines' ), 'tiles' => array( 'streak' ) ) );

		self::assertSame(
			array( 'sections' => array( 'tiles', 'schedule', 'deadlines' ), 'tiles' => array( 'streak' ) ),
			$this->service->save( 5, array( 'tiles' => array( 'streak', 'bogus' ) ) )
		);
	}

	public function test_staff_schema_keeps_order_and_rejects_learner_tiles(): void {
		$raw = array( 'sections' => array( 'attention', 'tiles', 'schedule' ), 'tiles' => array( 'attendance', 'to_review', 'ranking:python', 'lessons_today' ) );
		foreach ( array( UserRole::FSTeacher, UserRole::FSOffice ) as $role ) {
			self::assertSame( array( 'sections' => $raw['sections'], 'tiles' => array( 'to_review', 'lessons_today' ) ), $this->service->normalize( $raw, $role ) );
			self::assertSame( array( 'tiles', 'schedule', 'attention' ), $this->service->defaultLayout( $role )['sections'] );
		}
	}

	public function test_parent_ranking_is_allowed_but_exam_countdown_is_not(): void {
		self::assertSame( array( 'ranking:python', 'attendance' ), $this->service->normalize( array( 'tiles' => array( 'exam:python', 'ranking:python', 'attendance', 'to_review' ) ), UserRole::FSParent )['tiles'] );
	}


	public function test_menu_order_uses_available_screen_whitelist_and_students_cannot_hide_even_with_malicious_input(): void {
		$raw = array( 'menuOrder' => array( 'student-grades', 'exam-payments', 'student-home', 'student-grades' ), 'menuHidden' => array( 'student-home' ) );
		$layout = $this->service->normalize( $raw, UserRole::FSStudent, array( 'student-home', 'student-grades' ) );
		self::assertSame( array( 'student-grades', 'student-home' ), $layout['menuOrder'] );
		self::assertSame( array(), $layout['menuHidden'] );
		self::assertSame( array(), $this->service->normalize( array( 'menuHidden' => array( 'parent-home' ) ), UserRole::FSParent )['menuHidden'] );
	}

	public function test_menu_and_home_partial_updates_keep_each_others_preferences(): void {
		$stored = array( 'sections' => array( 'attention', 'schedule', 'tiles' ), 'tiles' => array( 'to_review' ), 'menuOrder' => array( 'journal', 'dashboard' ), 'menuHidden' => array( 'journal' ) );
		$this->users->method( 'homeLayout' )->willReturn( $stored );
		$this->users->expects( self::exactly( 2 ) )->method( 'setHomeLayout' );
		$menu = $this->service->save( 5, array( 'menuOrder' => array( 'dashboard', 'journal' ) ), UserRole::FSTeacher, array( 'dashboard', 'journal' ) );
		self::assertSame( $stored['sections'], $menu['sections'] );
		self::assertSame( $stored['tiles'], $menu['tiles'] );
		self::assertSame( array( 'journal' ), $menu['menuHidden'] );
		$home = $this->service->save( 5, array( 'tiles' => null ), UserRole::FSTeacher, array( 'dashboard', 'journal' ) );
		self::assertSame( $stored['menuOrder'], $home['menuOrder'] );
		self::assertSame( $stored['menuHidden'], $home['menuHidden'] );
	}

	public function test_staff_can_hide_only_current_available_menu_items_and_reset_restores_visibility(): void {
		$raw = array( 'menuOrder' => array( 'journal', 'groups', 'exam-results' ), 'menuHidden' => array( 'journal', 'exam-results', 'exam-payments' ) );
		$layout = $this->service->normalize( $raw, UserRole::FSTeacher, array( 'dashboard', 'journal', 'exam-results' ) );
		self::assertSame( array( 'journal', 'exam-results' ), $layout['menuHidden'] );
		self::assertSame( array( 'journal', 'exam-results' ), $layout['menuOrder'] );
		$reset = $this->service->normalize( array( 'menuOrder' => array(), 'menuHidden' => array() ), UserRole::FSTeacher );
		self::assertSame( array(), $reset['menuHidden'] );
	}

}
