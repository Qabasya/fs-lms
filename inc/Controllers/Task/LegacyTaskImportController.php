<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Callbacks\Task\LegacyTaskImportCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class LegacyTaskImportController
 *
 * Регистрирует AJAX-хуки разового переноса заданий со старой версии сайта.
 * Страница инструмента — {@see LegacyTaskImportPageController}, подключена
 * скрытой подстраницей в AdminController (аналогично BoilerplateManager).
 *
 * @package Inc\Controllers\Task
 */
class LegacyTaskImportController extends AjaxController {

	public function __construct(
		private readonly LegacyTaskImportCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::LegacyTaskImportStatus, $this->callbacks ),
			array( AjaxHook::LegacyTaskImportBatch, $this->callbacks ),
		);
	}
}
