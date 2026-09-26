<?php

declare( strict_types=1 );

namespace Inc\Controllers\Print;

use Inc\Callbacks\Print\PrintCenterCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class PrintCenterController
 *
 * AJAX-хуки «Центра печати». Страницу меню регистрирует AdminController.
 *
 * @package Inc\Controllers\Print
 */
class PrintCenterController extends AjaxController {

	public function __construct(
		private readonly PrintCenterCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SearchPrintStudents, $this->callbacks ),
			array( AjaxHook::GetPrintStudentRecords, $this->callbacks ),
			array( AjaxHook::GeneratePrintDocument, $this->callbacks ),
			array( AjaxHook::SavePrintProgram, $this->callbacks ),
		);
	}
}
