<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\LogsCallbacks;
use Inc\Enums\AjaxHook;

class LogsController extends AjaxController {

	public function __construct(
		private readonly LogsCallbacks $logsCallbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		parent::register();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::ExportAuditLog, $this->logsCallbacks ),
			array( AjaxHook::ExportPiiLog,   $this->logsCallbacks ),
		);
	}
}
