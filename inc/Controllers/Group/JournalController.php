<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Course\JournalCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class JournalController
 *
 * Регистрирует AJAX журнала/посещаемости (ЛК, Эпик 2).
 *
 * @package Inc\Controllers\Group
 */
class JournalController extends AjaxController {

	public function __construct(
		private readonly JournalCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetGroupJournal, $this->callbacks ),
			array( AjaxHook::SaveAttendance,  $this->callbacks ),
			array( AjaxHook::BulkAttendance,  $this->callbacks ),
		);
	}
}
