<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Callbacks\MasterclassSettingsCallbacks;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\Controllers\MasterclassSettingsController;
use PHPUnit\Framework\TestCase;

/**
 * Рендер настоящих шаблонов модуля: вкладка настроек показывает текущие значения и границы,
 * страница модуля открывается с заголовком.
 */
class MasterclassTemplatesRenderTest extends TestCase {

	private const TEMPLATES = __DIR__ . '/../../../../inc/Modules/Masterclass/templates/';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_options'] = array();
		$this->enableModule();
	}

	protected function tearDown(): void {
		$GLOBALS['_test_options'] = array();
		parent::tearDown();
	}

	/**
	 * Рендерит вкладку так, как это делает `templates/admin/settings.php`: в области видимости уже есть `$config` — массив
	 * настроек плагина, — а данные вкладки подмешиваются через `extract( …, EXTR_SKIP )`, которое существующее не перезапишет.
	 * Так и поймана ошибка «Call to a member function startTime() on array» на живой странице.
	 */
	private function renderSettings(): string {
		$tab = ( new MasterclassSettingsController( $this->createStub( MasterclassSettingsCallbacks::class ), new MasterclassConfig() ) )
			->registerSettingsTab( array() );

		self::assertArrayHasKey( MasterclassSettingsController::TAB, $tab, 'вкладка есть только при включённом модуле' );
		$path = $tab[ MasterclassSettingsController::TAB ]['path'];
		$data = $tab[ MasterclassSettingsController::TAB ]['data'];

		ob_start();
		( static function () use ( $path, $data ): void {
			$config = array( 'center_phone' => '+7 000' ); // как на странице настроек плагина
			extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			require $path;
		} )();

		return (string) ob_get_clean();
	}

	private function enableModule( array $over = array() ): void {
		$GLOBALS['_test_options'][ MasterclassConfig::OPTION ] = array_merge( array( 'enabled' => true ), $over );
	}

	public function test_settings_tab_renders_every_field_with_the_current_value(): void {
		$this->enableModule(
			array(
				'start_time'           => '10:00',
				'end_time'             => '13:00',
				'batch_size'           => 80,
				'home_timeout_minutes' => 90,
				'freeze_days'          => 21,
				'unused_purge_days'    => 10,
				'frozen_purge_days'    => 45,
				'log_retention_days'   => 400,
				'messenger_url'        => 'https://t.me/school',
				'banner_text'          => 'Запишитесь к нам',
			)
		);

		$html = $this->renderSettings();

		foreach (
			array(
				'start_time'           => '10:00',
				'end_time'             => '13:00',
				'batch_size'           => '80',
				'home_timeout_minutes' => '90',
				'freeze_days'          => '21',
				'unused_purge_days'    => '10',
				'frozen_purge_days'    => '45',
				'log_retention_days'   => '400',
				'messenger_url'        => 'https://t.me/school',
				'banner_text'          => 'Запишитесь к нам',
			) as $name => $value
		) {
			self::assertMatchesRegularExpression(
				'/<input [^>]*name="' . $name . '"[^>]*value="' . preg_quote( $value, '/' ) . '"/u',
				$html,
				"поле $name показывает сохранённое значение"
			);
		}
	}

	public function test_settings_tab_shows_defaults_without_a_saved_option(): void {
		$html = $this->renderSettings();

		self::assertStringContainsString( 'value="09:00"', $html );
		self::assertStringContainsString( 'value="14:00"', $html );
		self::assertStringContainsString( 'value="60"', $html );
		self::assertStringContainsString( 'value="' . MasterclassConfig::DEFAULT_BANNER_TEXT . '"', $html );
	}

	public function test_numeric_fields_carry_the_config_bounds(): void {
		$html = $this->renderSettings();

		foreach ( MasterclassConfig::INT_BOUNDS as $name => [ , $min, $max ] ) {
			self::assertMatchesRegularExpression(
				'/name="' . $name . '"[^>]*min="' . $min . '"[^>]*max="' . $max . '"/',
				$html,
				"у $name в разметке те же границы, что в конфиге"
			);
		}
	}

	public function test_banner_field_is_limited_in_length(): void {
		self::assertMatchesRegularExpression( '/name="banner_text"[^>]*maxlength="300"/', $this->renderSettings() );
	}

	public function test_settings_tab_has_the_form_and_the_hooks_for_the_script(): void {
		$html = $this->renderSettings();

		self::assertStringContainsString( 'id="fs-masterclass-form"', $html );
		self::assertStringContainsString( 'id="fs-masterclass-save"', $html );
		self::assertStringContainsString( 'id="fs-masterclass-status"', $html );
		self::assertStringNotContainsString( 'style=', $html, 'инлайновых стилей нет' );
		self::assertStringNotContainsString( '<svg', $html, 'инлайновых иконок нет' );
		self::assertStringNotContainsString( 'name="enabled"', $html, 'тумблер модуля живёт на Dashboard' );
	}

	public function test_admin_page_opens_with_the_title(): void {
		ob_start();
		( static function (): void {
			require self::TEMPLATES . 'admin-page.php';
		} )();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Мастер-классы', $html );
		self::assertStringContainsString( 'fs-page-header', $html );
		self::assertStringNotContainsString( 'style=', $html );
	}
}
