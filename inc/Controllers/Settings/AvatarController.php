<?php

declare( strict_types=1 );

namespace Inc\Controllers\Settings;

use Inc\Callbacks\Settings\AvatarCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрирует AJAX вкладки «Настройки → Аватары».
 */
class AvatarController extends AjaxController {

	public function __construct(
		private readonly AvatarCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SaveAvatar, $this->callbacks ),
		);
	}
}
