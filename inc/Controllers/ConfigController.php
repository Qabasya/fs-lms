<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Settings\ConfigCallbacks;
use Inc\Enums\AjaxHook;

class ConfigController extends AjaxController {

	public function __construct(
		private readonly ConfigCallbacks $configCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SaveConfig, $this->configCallbacks ),
			array( AjaxHook::GenerateKey, $this->configCallbacks ),
		);
	}
}
