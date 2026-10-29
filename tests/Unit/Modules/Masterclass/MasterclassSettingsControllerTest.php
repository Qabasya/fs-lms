<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Callbacks\MasterclassSettingsCallbacks;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\Controllers\MasterclassCronController;
use Inc\Modules\Masterclass\Controllers\MasterclassSettingsController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Вкладка настроек, карточка Dashboard и тумблер модуля.
 */
class MasterclassSettingsControllerTest extends TestCase {

	private MasterclassConfig $config;
	private MasterclassSettingsController $controller;
	private MasterclassCronController&MockObject $cron;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_options'] = array();
		$this->config             = new MasterclassConfig();
		$this->cron               = $this->createMock( MasterclassCronController::class );
		$this->controller         = new MasterclassSettingsController(
			$this->createStub( MasterclassSettingsCallbacks::class ),
			$this->config,
			$this->cron
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_test_options'] = array();
		parent::tearDown();
	}

	public function test_the_tab_is_absent_while_the_module_is_off(): void {
		$tabs = $this->controller->registerSettingsTab( array( 'tab-1' => array( 'title' => 'Предметы' ) ) );

		self::assertSame( array( 'tab-1' ), array_keys( $tabs ) );
	}

	public function test_the_tab_appears_when_the_module_is_on_and_carries_its_config(): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'enabled' => true );

		$tabs = $this->controller->registerSettingsTab( array( 'tab-1' => array( 'title' => 'Предметы' ) ) );

		self::assertArrayHasKey( 'tab-masterclasses', $tabs );
		self::assertSame( 'Мастер-классы', $tabs['tab-masterclasses']['title'] );
		self::assertSame( $this->config, $tabs['tab-masterclasses']['data']['masterclass_config'] );
		self::assertArrayNotHasKey( 'config', $tabs['tab-masterclasses']['data'], 'страница настроек держит под $config массив настроек плагина' );
		self::assertFileExists( $tabs['tab-masterclasses']['path'] );
		self::assertStringEndsWith( 'settings-tab.php', $tabs['tab-masterclasses']['path'] );
		self::assertArrayHasKey( 'tab-1', $tabs, 'чужие вкладки на месте' );
	}

	public function test_the_dashboard_card_describes_the_toggle(): void {
		$modules = $this->controller->registerDashboardModule( array( array( 'id' => 'other' ) ) );

		self::assertCount( 2, $modules );
		self::assertSame( 'other', $modules[0]['id'], 'чужие карточки на месте' );
		self::assertSame( 'masterclass', $modules[1]['id'] );
		self::assertSame( 'Мастер-классы', $modules[1]['title'] );
		self::assertFalse( $modules[1]['enabled'] );
		self::assertFalse( $modules[1]['const_locked'] );
		self::assertSame( 'FS_LMS_MASTERCLASS', $modules[1]['const_key'] );
		self::assertNotSame( '', $modules[1]['description'] );
	}

	public function test_the_dashboard_card_reflects_the_enabled_option(): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'enabled' => true );

		self::assertTrue( $this->controller->registerDashboardModule( array() )[0]['enabled'] );
	}

	public function test_the_toggle_is_saved_in_the_modules_own_option(): void {
		$this->controller->onToggle( true );
		self::assertTrue( $GLOBALS['_test_options'][ MasterclassConfig::OPTION ]['enabled'] );
		self::assertTrue( $this->config->isEnabled() );

		$this->controller->onToggle( false );
		self::assertFalse( $GLOBALS['_test_options'][ MasterclassConfig::OPTION ]['enabled'] );
		self::assertFalse( $this->config->isEnabled() );
	}

	public function test_disabling_the_module_unschedules_the_tick_and_enabling_does_not(): void {
		$this->cron->expects( self::once() )->method( 'unschedule' );

		$this->controller->onToggle( true );
		$this->controller->onToggle( false );
	}

	public function test_toggle_keeps_the_other_settings(): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array( 'freeze_days' => 20 );

		$this->controller->onToggle( true );

		self::assertSame( 20, $GLOBALS['_test_options'][ MasterclassConfig::OPTION ]['freeze_days'] );
	}

	public function test_action_and_tab_names_are_stable(): void {
		self::assertSame( 'fs_lms_masterclass_save_settings', MasterclassSettingsController::SAVE_ACTION );
		self::assertSame( 'tab-masterclasses', MasterclassSettingsController::TAB );
	}
}
