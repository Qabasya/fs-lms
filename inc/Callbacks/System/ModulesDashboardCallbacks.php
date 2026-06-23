<?php

declare( strict_types=1 );

namespace Inc\Callbacks\System;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Access\Capability;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX-обработчики страницы управления модулями (Dashboard).
 *
 * Переключение enabled-флага конкретного модуля делегируется самому модулю
 * через generic-хук `fs_lms_module_toggle_{id}`, чтобы ядро не знало о внутренностях модулей.
 */
class ModulesDashboardCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct() {
		parent::__construct();
	}

	public function ajaxToggleModule(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$module  = $this->requireKey( 'module' );
		$enabled = $this->sanitizeBool( 'enabled' );

		// Модуль сам знает, как сохранить свой флаг
		do_action( "fs_lms_module_toggle_{$module}", $enabled );

		$this->success( array( 'enabled' => $enabled ) );
	}
}
