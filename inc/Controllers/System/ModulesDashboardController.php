<?php

declare( strict_types=1 );

namespace Inc\Controllers\System;

use Inc\Callbacks\System\ModulesDashboardCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;

/**
 * Контроллер страницы управления модулями (Dashboard).
 * Регистрирует AJAX для переключения enabled-флагов.
 * Данные о модулях поставляют сами модули через фильтр `fs_lms_dashboard_modules`.
 */
class ModulesDashboardController extends BaseController implements ServiceInterface {

	public const TOGGLE_ACTION = 'fs_lms_toggle_module';

	public function __construct(
		private readonly ModulesDashboardCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::TOGGLE_ACTION, array( $this->callbacks, 'ajaxToggleModule' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	public function enqueueAssets( string $hook ): void {
		// Только на Dashboard (главная страница плагина)
		if ( 'toplevel_page_fs_lms' !== $hook ) {
			return;
		}

		wp_localize_script(
			'fs-lms-admin-script',
			'fsLmsModules',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::TOGGLE_ACTION,
				'nonce'   => Nonce::Config->create(),
			)
		);
	}
}
