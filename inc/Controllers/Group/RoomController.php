<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Course\RoomCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрирует AJAX справочника кабинетов (офис, Эпик 9).
 *
 * @package Inc\Controllers\Group
 */
class RoomController extends AjaxController {

	public function __construct(
		private readonly RoomCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetRooms,        $this->callbacks ),
			array( AjaxHook::SaveRoom,        $this->callbacks ),
			array( AjaxHook::DeleteRoom,      $this->callbacks ),
			array( AjaxHook::AssignGroupRoom, $this->callbacks ),
		);
	}
}
