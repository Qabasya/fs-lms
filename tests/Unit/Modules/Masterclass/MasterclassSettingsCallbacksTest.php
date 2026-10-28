<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Callbacks\MasterclassSettingsCallbacks;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use PHPUnit\Framework\TestCase;

/**
 * AJAX сохранения настроек: права и nonce, только присланные ключи, приведение значений, отказ при плохом вводе.
 */
class MasterclassSettingsCallbacksTest extends TestCase {

	private MasterclassSettingsCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 1;
		$GLOBALS['_test_options']    = array();

		$this->cb = new MasterclassSettingsCallbacks( new MasterclassConfig() );
	}

	protected function tearDown(): void {
		$GLOBALS['_test_options'] = array();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function stored(): array {
		return $GLOBALS['_test_options'][ MasterclassConfig::OPTION ] ?? array();
	}

	/** @param array<string, mixed> $post */
	private function save( array $post ): \FsTestJsonResponse {
		$_POST = $post;

		return fs_test_capture_json( fn() => $this->cb->ajaxSave() );
	}

	public function test_without_capability_nothing_is_saved(): void {
		$GLOBALS['_fs_test_can'] = false;

		$response = $this->save( array( 'batch_size' => '100' ) );

		self::assertFalse( $response->success );
		self::assertSame( array(), $this->stored() );
	}

	public function test_with_a_bad_nonce_nothing_is_saved(): void {
		$GLOBALS['_fs_test_nonce_ok'] = false;

		$response = $this->save( array( 'batch_size' => '100' ) );

		self::assertFalse( $response->success );
		self::assertSame( array(), $this->stored() );
	}

	public function test_the_capability_checked_is_the_platform_one(): void {
		$asked = array();
		$GLOBALS['_fs_test_can_callback'] = static function ( $cap ) use ( &$asked ): bool {
			$asked[] = $cap;

			return true;
		};

		$this->save( array( 'batch_size' => '100' ) );
		unset( $GLOBALS['_fs_test_can_callback'] );

		self::assertContains( \Inc\Enums\Access\Capability::ManageLmsPlatform->value, $asked );
	}

	public function test_saves_only_the_sent_keys_and_keeps_the_rest(): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'freeze_days' => 20, 'enabled' => true );

		$response = $this->save( array( 'batch_size' => '100' ) );

		self::assertTrue( $response->success );
		self::assertSame( 100, $this->stored()['batch_size'] );
		self::assertSame( 20, $this->stored()['freeze_days'], 'непришедший ключ остался прежним' );
		self::assertTrue( $this->stored()['enabled'] );
	}

	public function test_values_outside_the_bounds_are_clamped(): void {
		$response = $this->save( array( 'batch_size' => '99999', 'freeze_days' => '0', 'home_timeout_minutes' => '1', 'log_retention_days' => '5' ) );

		self::assertTrue( $response->success );
		self::assertSame( 500, $this->stored()['batch_size'] );
		self::assertSame( 1, $this->stored()['freeze_days'] );
		self::assertSame( 5, $this->stored()['home_timeout_minutes'] );
		self::assertSame( 30, $this->stored()['log_retention_days'] );
	}

	public function test_the_enabled_toggle_cannot_be_changed_here(): void {
		$this->save( array( 'enabled' => '1', 'batch_size' => '80' ) );
		self::assertFalse( $this->stored()['enabled'], 'модуль включается на Dashboard, а не отсюда' );

		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'enabled' => true );
		$this->save( array( 'enabled' => '0', 'batch_size' => '80' ) );
		self::assertTrue( $this->stored()['enabled'], 'и выключить его отсюда нельзя' );
	}

	public function test_saves_the_window_texts_and_the_messenger(): void {
		$response = $this->save(
			array(
				'start_time'    => '10:00',
				'end_time'      => '13:30',
				'messenger_url' => 'https://t.me/school',
				'banner_text'   => 'Запишитесь к нам',
			)
		);

		self::assertTrue( $response->success );
		self::assertSame( '10:00', $this->stored()['start_time'] );
		self::assertSame( '13:30', $this->stored()['end_time'] );
		self::assertSame( 'https://t.me/school', $this->stored()['messenger_url'] );
		self::assertSame( 'Запишитесь к нам', $this->stored()['banner_text'] );
	}

	public function test_an_empty_messenger_clears_the_link(): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'messenger_url' => 'https://t.me/old' );

		$response = $this->save( array( 'messenger_url' => '' ) );

		self::assertTrue( $response->success );
		self::assertSame( '', $this->stored()['messenger_url'] );
	}

	public function test_a_non_link_messenger_is_refused_and_nothing_is_saved(): void {
		$response = $this->save( array( 'messenger_url' => 'не ссылка', 'batch_size' => '100' ) );

		self::assertFalse( $response->success );
		self::assertSame( array(), $this->stored(), 'при отказе не сохраняется и остальное' );
	}

	public function test_a_broken_time_is_refused_and_nothing_is_saved(): void {
		$response = $this->save( array( 'start_time' => '25:00', 'batch_size' => '100' ) );

		self::assertFalse( $response->success );
		self::assertStringContainsString( 'ЧЧ:ММ', (string) $response->payload );
		self::assertSame( array(), $this->stored() );
	}

	public function test_an_end_before_the_start_is_refused(): void {
		$response = $this->save( array( 'start_time' => '14:00', 'end_time' => '09:00' ) );

		self::assertFalse( $response->success );
		self::assertStringContainsString( 'позже начала', (string) $response->payload );
		self::assertSame( array(), $this->stored() );
	}

	public function test_an_empty_request_changes_nothing(): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'freeze_days' => 20, 'enabled' => true );

		$response = $this->save( array() );

		self::assertTrue( $response->success );
		self::assertSame( 20, $this->stored()['freeze_days'], 'присланных ключей нет — настройки остались прежними' );
		self::assertTrue( $this->stored()['enabled'] );
	}
}
