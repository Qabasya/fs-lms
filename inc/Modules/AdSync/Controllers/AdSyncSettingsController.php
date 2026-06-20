<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Modules\AdSync\Callbacks\AdSyncSettingsCallbacks;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Services\Application\ApplicationSettingsService;

/**
 * Class AdSyncSettingsController
 *
 * Admin-настройки модуля AdSync: рендер своей секции в табе «Конфигурация» через
 * generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает), AJAX-сохранение,
 * и собственный admin-JS (модуль self-contained, не лезет в core-бандл).
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdSyncSettingsController extends BaseController {

	/** Собственное имя AJAX-действия (вне core AjaxHook — изоляция). */
	public const SAVE_ACTION = 'fs_lms_ad_sync_save';

	public function __construct(
		private readonly AdSyncSettingsCallbacks    $callbacks,
		private readonly AdSyncConfig               $config,
		private readonly ApplicationSettingsService $applicationSettings,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SAVE_ACTION, array( $this->callbacks, 'ajaxSaveSettings' ) );
		add_action( 'fs_lms_config_sections', array( $this, 'renderSection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Рендерит секцию настроек AD. Вызывается из generic-хука ядра (передаёт список предметов).
	 *
	 * @param array $subjects Список предметов (из шаблона таба конфигурации).
	 */
	public function renderSection( array $subjects = array() ): void {
		$config           = $this->config;                              // используется в шаблоне
		$bind_to_subject  = $this->applicationSettings->isBindToSubject(); // зависимость AD ← привязка к направлению
		require $this->path( 'inc/Modules/AdSync/templates/settings-section.php' );
	}

	public function enqueueAssets( string $hook ): void {
		if ( 'fs_lms_settings' !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
			return;
		}

		$rel = 'inc/Modules/AdSync/assets/admin.js';
		wp_enqueue_script(
			'fs-lms-ad-sync',
			$this->url( $rel ),
			array( 'jquery' ),
			(string) filemtime( $this->path( $rel ) ),
			true
		);
		wp_localize_script( 'fs-lms-ad-sync', 'fsLmsAdSync', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::SAVE_ACTION,
			'nonce'   => Nonce::Config->create(),
		) );
	}
}
