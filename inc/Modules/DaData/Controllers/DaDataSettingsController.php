<?php

declare( strict_types=1 );

namespace Inc\Modules\DaData\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\DaData\Callbacks\DaDataSettingsCallbacks;
use Inc\Modules\DaData\Config\DaDataConfig;

/**
 * Class DaDataSettingsController
 *
 * Admin-настройки модуля DaData: секция в табе «Конфигурация» через generic-хук ядра
 * `fs_lms_config_sections` (ядро о модуле не знает), регистрация в Dashboard
 * (`fs_lms_dashboard_modules`), тумблер вкл/выкл (`fs_lms_module_toggle_dadata`)
 * и собственный admin-JS (сохранение токена; модуль self-contained, не лезет в core-бандл).
 *
 * Секция в Конфигурации показывается только когда модуль включён.
 *
 * @package Inc\Modules\DaData\Controllers
 */
class DaDataSettingsController extends BaseController {

	/** Собственное имя AJAX-действия (вне core AjaxHook — изоляция). */
	public const SAVE_ACTION = 'fs_lms_dadata_save';

	public function __construct(
		private readonly DaDataSettingsCallbacks $callbacks,
		private readonly DaDataConfig            $config,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SAVE_ACTION, array( $this->callbacks, 'ajaxSaveSettings' ) );
		add_action( 'fs_lms_config_sections', array( $this, 'renderSection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_dadata', array( $this, 'onToggle' ) );
	}

	/**
	 * Рендерит секцию настроек DaData. Вызывается из generic-хука ядра.
	 * Показывается только когда модуль включён.
	 *
	 * @param array $subjects Список предметов (из шаблона таба конфигурации; модулю не нужен).
	 */
	public function renderSection( array $subjects = array() ): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$config = $this->config;
		require $this->path( 'inc/Modules/DaData/templates/settings-section.php' );
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$modules[] = array(
			'id'           => 'dadata',
			'title'        => 'Автодополнение DaData',
			'description'  => 'Подсказки ФИО и адреса на форме завершения регистрации (/lms/join) через API DaData. При включении в Конфигурации появляется секция с вводом токена.',
			'enabled'      => $this->config->isEnabled(),
			'const_locked' => defined( 'FS_LMS_DADATA' ),
			'const_key'    => 'FS_LMS_DADATA',
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

		$rel  = 'inc/Modules/DaData/assets/admin.js';
		$path = $this->path( $rel );
		wp_enqueue_script(
			'fs-lms-dadata',
			$this->url( $rel ),
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
		wp_localize_script( 'fs-lms-dadata', 'fsLmsDaData', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::SAVE_ACTION,
			'nonce'   => Nonce::Config->create(),
		) );
	}
}
