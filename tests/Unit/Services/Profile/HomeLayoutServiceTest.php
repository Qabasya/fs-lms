<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Managers\Person\UserManager;
use Inc\Services\Profile\HomeLayoutService;
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
}
