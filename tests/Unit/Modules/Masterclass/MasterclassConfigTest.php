<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Config\MasterclassConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Настройки мастер-классов: значения по умолчанию, границы чисел, окно по умолчанию, приведение ввода.
 */
class MasterclassConfigTest extends TestCase {

	private MasterclassConfig $config;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_options'] = array();
		$this->config             = new MasterclassConfig();
	}

	protected function tearDown(): void {
		$GLOBALS['_test_options'] = array();
		parent::tearDown();
	}

	/** @param array<string, mixed> $stored */
	private function store( array $stored ): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = $stored;
	}

	public function test_defaults_match_the_spec(): void {
		self::assertFalse( $this->config->isEnabled() );
		self::assertSame( '09:00', $this->config->startTime() );
		self::assertSame( '14:00', $this->config->endTime() );
		self::assertSame( 60, $this->config->batchSize() );
		self::assertSame( 60, $this->config->homeTimeoutMinutes() );
		self::assertSame( 14, $this->config->freezeDays() );
		self::assertSame( 7, $this->config->unusedPurgeDays() );
		self::assertSame( 30, $this->config->frozenPurgeDays() );
		self::assertSame( 365, $this->config->logRetentionDays() );
		self::assertSame( '', $this->config->messengerUrl() );
		self::assertSame( 'Свяжитесь с администратором, чтобы записаться на занятия', $this->config->bannerText() );
	}

	public function test_option_and_constant_names_are_stable(): void {
		self::assertSame( 'fs_lms_masterclass', MasterclassConfig::OPTION );
		self::assertSame( 'FS_LMS_MASTERCLASS', MasterclassConfig::TOGGLE_CONSTANT );
	}

	public function test_enabled_flag_follows_the_option(): void {
		$this->store( array( 'enabled' => true ) );

		self::assertTrue( $this->config->isEnabled() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: int}> Ключ, геттер, по умолчанию, минимум, максимум.
	 */
	public static function intSettings(): array {
		return array(
			'batch_size'           => array( 'batch_size', 'batchSize', 60, 1, 500 ),
			'home_timeout_minutes' => array( 'home_timeout_minutes', 'homeTimeoutMinutes', 60, 5, 720 ),
			'freeze_days'          => array( 'freeze_days', 'freezeDays', 14, 1, 60 ),
			'unused_purge_days'    => array( 'unused_purge_days', 'unusedPurgeDays', 7, 1, 60 ),
			'frozen_purge_days'    => array( 'frozen_purge_days', 'frozenPurgeDays', 30, 1, 365 ),
			'log_retention_days'   => array( 'log_retention_days', 'logRetentionDays', 365, 30, 1095 ),
		);
	}

	#[DataProvider( 'intSettings' )]
	public function test_integer_settings_are_clamped_to_their_bounds( string $key, string $getter, int $default, int $min, int $max ): void {
		$this->store( array( $key => $min - 1 ) );
		self::assertSame( $min, $this->config->$getter(), "$key ниже границы → минимум" );

		$this->store( array( $key => $max + 1 ) );
		self::assertSame( $max, $this->config->$getter(), "$key выше границы → максимум" );

		$this->store( array( $key => (string) $max ) );
		self::assertSame( $max, $this->config->$getter(), "$key строкой в границах читается как число" );

		foreach ( array( 'abc', '', null, array(), true ) as $garbage ) {
			$this->store( array( $key => $garbage ) );
			self::assertSame( $default, $this->config->$getter(), "$key: мусор → по умолчанию" );
		}
	}

	#[DataProvider( 'intSettings' )]
	public function test_bounds_table_matches_the_getters( string $key, string $getter, int $default, int $min, int $max ): void {
		self::assertSame( array( $default, $min, $max ), MasterclassConfig::INT_BOUNDS[ $key ] );
	}

	public function test_valid_window_is_returned(): void {
		$this->store( array( 'start_time' => '10:30', 'end_time' => '13:15' ) );

		self::assertSame( '10:30', $this->config->startTime() );
		self::assertSame( '13:15', $this->config->endTime() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function brokenWindows(): array {
		return array(
			'конец раньше начала'  => array( '15:00', '10:00' ),
			'конец равен началу'   => array( '12:00', '12:00' ),
			'час вне суток'        => array( '09:00', '24:00' ),
			'минуты вне часа'      => array( '09:60', '14:00' ),
			'без нуля в часе'      => array( '9:00', '14:00' ),
			'секунды'              => array( '09:00:00', '14:00' ),
			'мусор'                => array( 'утро', 'вечер' ),
			'пустые'               => array( '', '' ),
		);
	}

	#[DataProvider( 'brokenWindows' )]
	public function test_broken_window_falls_back_to_the_default_pair( string $start, string $end ): void {
		$this->store( array( 'start_time' => $start, 'end_time' => $end ) );

		self::assertSame( '09:00', $this->config->startTime() );
		self::assertSame( '14:00', $this->config->endTime() );
		self::assertGreaterThan( $this->config->startTime(), $this->config->endTime(), 'конец окна всегда позже начала' );
	}

	public function test_banner_text_is_trimmed_cut_and_never_empty(): void {
		$this->store( array( 'banner_text' => "  Запишитесь!  " ) );
		self::assertSame( 'Запишитесь!', $this->config->bannerText() );

		$this->store( array( 'banner_text' => str_repeat( 'я', 400 ) ) );
		self::assertSame( MasterclassConfig::BANNER_MAX, mb_strlen( $this->config->bannerText() ) );

		$this->store( array( 'banner_text' => '   ' ) );
		self::assertSame( MasterclassConfig::DEFAULT_BANNER_TEXT, $this->config->bannerText(), 'пустой текст заменяется текстом по умолчанию' );
	}

	public function test_messenger_url_is_trimmed(): void {
		$this->store( array( 'messenger_url' => '  https://t.me/school  ' ) );

		self::assertSame( 'https://t.me/school', $this->config->messengerUrl() );
	}

	public function test_normalize_clamps_numbers_and_keeps_only_sent_keys(): void {
		$clean = $this->config->normalize(
			array(
				'batch_size'  => 9999,
				'freeze_days' => 0,
				'unknown'     => 'x',
				'enabled'     => true,
			)
		);

		self::assertSame( array( 'batch_size' => 500, 'freeze_days' => 1 ), $clean, 'чужие ключи и `enabled` отбрасываются, непришедшие не появляются' );
	}

	public function test_normalize_cuts_banner_and_trims_url(): void {
		$clean = $this->config->normalize(
			array(
				'banner_text'   => '  ' . str_repeat( 'ы', 350 ),
				'messenger_url' => '  https://vk.me/school ',
			)
		);

		self::assertSame( MasterclassConfig::BANNER_MAX, mb_strlen( $clean['banner_text'] ) );
		self::assertSame( 'https://vk.me/school', $clean['messenger_url'] );
	}

	public function test_normalize_accepts_a_valid_window(): void {
		self::assertSame(
			array( 'start_time' => '08:00', 'end_time' => '12:30' ),
			$this->config->normalize( array( 'start_time' => '08:00', 'end_time' => '12:30' ) )
		);
	}

	public function test_normalize_rejects_a_time_in_the_wrong_format(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'ЧЧ:ММ' );

		$this->config->normalize( array( 'start_time' => '8 утра' ) );
	}

	public function test_normalize_rejects_an_end_not_later_than_the_start(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'позже начала' );

		$this->config->normalize( array( 'start_time' => '14:00', 'end_time' => '09:00' ) );
	}

	public function test_normalize_checks_a_single_time_against_the_stored_partner(): void {
		$this->store( array( 'start_time' => '09:00', 'end_time' => '14:00' ) );

		self::assertSame( array( 'end_time' => '15:00' ), $this->config->normalize( array( 'end_time' => '15:00' ) ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->config->normalize( array( 'end_time' => '08:00' ) );
	}

	public function test_save_stores_only_known_keys_and_keeps_the_rest(): void {
		$this->store( array( 'freeze_days' => 20 ) );

		$this->config->save( array( 'batch_size' => 100, 'foreign' => 'x' ) );

		$stored = $GLOBALS['_test_options'][ MasterclassConfig::OPTION ];
		self::assertSame( 100, $stored['batch_size'] );
		self::assertSame( 20, $stored['freeze_days'], 'прежнее значение не сброшено' );
		self::assertArrayNotHasKey( 'foreign', $stored );
	}
}
