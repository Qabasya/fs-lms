<?php

declare( strict_types=1 );

namespace Inc\Controllers\Profile;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Profile\LearnerCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрирует AJAX профиля учащегося/родителя (Эпик 7).
 *
 * @package Inc\Controllers\Profile
 */
class LearnerProfileController extends AjaxController {

	public function __construct(
		private readonly LearnerCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetLearnerProfile,   $this->callbacks ),
			// Эпик 15 (П10): самозапись ученика в открытую группу.
			array( AjaxHook::SelfEnrollOpenGroup, $this->callbacks ),
		);
	}
}
