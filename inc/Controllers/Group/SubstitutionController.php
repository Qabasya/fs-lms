<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Course\SubstitutionCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрирует AJAX-хуки замен преподавателя (офис, Эпик 5).
 *
 * @package Inc\Controllers\Group
 */
class SubstitutionController extends AjaxController {

	public function __construct(
		private readonly SubstitutionCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::AssignSubstitute,      $this->callbacks ),
			array( AjaxHook::RevokeSubstitute,      $this->callbacks ),
			array( AjaxHook::GetGroupSubstitutions, $this->callbacks ),
			array( AjaxHook::GetSubstitutionsData,  $this->callbacks ),
			array( AjaxHook::SetRoomOverride,       $this->callbacks ),
		);
	}
}
