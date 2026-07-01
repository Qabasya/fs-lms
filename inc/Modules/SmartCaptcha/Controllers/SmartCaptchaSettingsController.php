<?php

declare( strict_types=1 );

namespace Inc\Modules\SmartCaptcha\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\SmartCaptcha\Callbacks\SmartCaptchaSettingsCallbacks;
use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;

/**
 * Class SmartCaptchaSettingsController
 *
 * Admin-настройки модуля SmartCaptcha: секция в табе «Конфигурация» через generic-хук ядра
 * `fs_lms_config_sections` (ядро о модуле не знает), регистрация в Dashboard
 * (`fs_lms_dashboard_modules`), тумблер вкл/выкл (`fs_lms_module_toggle_smart_captcha`)
 * и собственный admin-JS (сохранение ключей; модуль self-contained, не лезет в core-бандл).
 *
 * Секция в Конфигурации показывается только когда модуль включён.
 *
 * @package Inc\Modules\SmartCaptcha\Controllers
 */
class SmartCaptchaSettingsController extends BaseController {

	/** Собственное имя AJAX-действия (вне core AjaxHook — изоляция). */
	public const SAVE_ACTION = 'fs_lms_smart_captcha_save';

	public function __construct(
		private readonly SmartCaptchaSettingsCallbacks $callbacks,
		private readonly SmartCaptchaConfig            $config,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SAVE_ACTION, array( $this->callbacks, 'ajaxSaveSettings' ) );
		add_action( 'fs_lms_config_sections', array( $this, 'renderSection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_smart_captcha', array( $this, 'onToggle' ) );
	}

	/**
	 * Рендерит секцию настроек SmartCaptcha. Вызывается из generic-хука ядра.
	 * Показывается только когда модуль включён.
	 *
	 * @param array $subjects Список предметов (из шаблона таба конфигурации; модулю не нужен).
	 */
	public function renderSection( array $subjects = array() ): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$config = $this->config;
		require $this->path( 'inc/Modules/SmartCaptcha/templates/settings-section.php' );
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$modules[] = array(
			'id'           => 'smart_captcha',
			'title'        => 'Yandex SmartCaptcha',
			'description'  => 'Защита формы заявки (/lms/apply) капчей Yandex SmartCaptcha. При включении в Конфигурации появляется секция с вводом ключей. Honeypot, rate-limit и OTP работают независимо от капчи.',
			'enabled'      => $this->config->isEnabled(),
			'const_locked' => defined( 'FS_LMS_SMART_CAPTCHA' ),
			'const_key'    => 'FS_LMS_SMART_CAPTCHA',
		);

		return $modules;
	}

	public function onToggle( bool $enabled ): void {
		$this->config->save( array( 'enabled' => $enabled ) );
	}

	public function enqueueAssets( string $hook ): void {
		if ( 'fs_lms_settings' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			return;
		}

		$rel = 'inc/Modules/SmartCaptcha/assets/admin.js';
		wp_enqueue_script(
			'fs-lms-smart-captcha',
			$this->url( $rel ),
			array( 'jquery' ),
			(string) filemtime( $this->path( $rel ) ),
			true
		);
		wp_localize_script( 'fs-lms-smart-captcha', 'fsLmsSmartCaptcha', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::SAVE_ACTION,
			'nonce'   => Nonce::Config->create(),
		) );
	}
}
