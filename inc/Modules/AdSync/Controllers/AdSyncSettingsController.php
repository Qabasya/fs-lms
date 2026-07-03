<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Callbacks\AdSyncSettingsCallbacks;
use Inc\Modules\AdSync\Config\AdSyncConfig;

/**
 * Class AdSyncSettingsController
 *
 * Admin-настройки модуля AdSync: рендер своей секции в табе «Конфигурация» через
 * generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает), AJAX-сохранение,
 * регистрация в Dashboard через `fs_lms_dashboard_modules`,
 * и собственный admin-JS (модуль self-contained, не лезет в core-бандл).
 *
 * Секция в Конфигурации показывается только когда модуль включён.
 * Enable-тумблер живёт на Dashboard — здесь только детальные настройки (HMAC).
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdSyncSettingsController extends BaseController {

	/** Собственное имя AJAX-действия (вне core AjaxHook — изоляция). */
	public const SAVE_ACTION = 'fs_lms_ad_sync_save';

	public function __construct(
		private readonly AdSyncSettingsCallbacks    $callbacks,
		private readonly AdSyncConfig               $config,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SAVE_ACTION, array( $this->callbacks, 'ajaxSaveSettings' ) );
		add_action( 'fs_lms_config_sections', array( $this, 'renderSection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_ad_sync', array( $this, 'onToggle' ) );
	}

	/**
	 * Рендерит секцию настроек AD. Вызывается из generic-хука ядра.
	 * Показывается только когда модуль включён — enable-тумблер на Dashboard.
	 *
	 * @param array $subjects Список предметов (из шаблона таба конфигурации).
	 */
	public function renderSection( array $subjects = array() ): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$config = $this->config;
		require $this->path( 'inc/Modules/AdSync/templates/settings-section.php' );
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$const_defined = defined( 'FS_LMS_AD_SYNC' );

		$modules[] = array(
			'id'           => 'ad_sync',
			'title'        => 'Синхронизация с доменом (AD)',
			'description'  => 'Создание учётных записей в Active Directory по заявкам. Python-сервис забирает задания с сайта (pull). При отключении исчезает секция «Синхронизация с доменом» в Конфигурации.',
			'enabled'      => $this->config->isEnabled(),
			'const_locked' => $const_defined,
			'const_key'    => 'FS_LMS_AD_SYNC',
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

		// Выключенный модуль свой JS не грузит (секция настроек всё равно скрыта).
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$rel  = 'inc/Modules/AdSync/assets/admin.js';
		$path = $this->path( $rel );
		wp_enqueue_script(
			'fs-lms-ad-sync',
			$this->url( $rel ),
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
		wp_localize_script( 'fs-lms-ad-sync', 'fsLmsAdSync', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::SAVE_ACTION,
			'nonce'   => Nonce::Config->create(),
		) );
	}
}
