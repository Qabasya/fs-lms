<?php

declare( strict_types=1 );

namespace Inc\Controllers\Profile;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Profile\LearnerActivityCallbacks;
use Inc\Callbacks\Profile\LearnerCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрирует AJAX профиля учащегося/родителя (Эпик 7).
 *
 * @package Inc\Controllers\Profile
 */
class LearnerProfileController extends AjaxController {

	public function __construct(
		private readonly LearnerCallbacks         $callbacks,
		private readonly LearnerActivityCallbacks $activityCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetLearnerProfile, $this->callbacks ),
			array( AjaxHook::SaveHomeLayout, $this->callbacks ),
			array( AjaxHook::LogLearnerActivity, $this->activityCallbacks ),
		);
	}
}
