<?php

declare( strict_types=1 );

namespace Inc\Modules\DaData\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\DaData\Config\DaDataConfig;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class DaDataSettingsCallbacks
 *
 * AJAX-обработчик настроек модуля DaData: сохранение API-токена в опцию модуля.
 * Включение/выключение модуля — на Dashboard (`fs_lms_module_toggle_dadata`).
 *
 * @package Inc\Modules\DaData\Callbacks
 */
class DaDataSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly DaDataConfig $config,
	) {
		parent::__construct();
	}

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$this->config->save( array( 'token' => $this->sanitizeText( 'dadata_token' ) ) );

		$this->success( array( 'message' => 'Токен DaData сохранён.' ) );
	}
}
