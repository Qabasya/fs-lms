<?php

declare( strict_types=1 );

namespace Inc\Modules\SmartCaptcha\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class SmartCaptchaSettingsCallbacks
 *
 * AJAX-обработчик настроек модуля SmartCaptcha: сохранение ключей в опцию модуля.
 * Включение/выключение модуля — на Dashboard (`fs_lms_module_toggle_smart_captcha`).
 *
 * @package Inc\Modules\SmartCaptcha\Callbacks
 */
class SmartCaptchaSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly SmartCaptchaConfig $config,
	) {
		parent::__construct();
	}

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$this->config->save( array(
			'site_key'   => $this->sanitizeText( 'captcha_site_key' ),
			'server_key' => $this->sanitizeText( 'captcha_server_key' ),
		) );

		$this->success( array( 'message' => 'Ключи SmartCaptcha сохранены.' ) );
	}
}
