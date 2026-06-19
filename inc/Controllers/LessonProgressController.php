<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Course\LessonPlayerCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class LessonProgressController
 *
 * Регистрирует AJAX-хук записи прогресса шага из пошагового плеера (★, T1.5.12).
 *
 * @package Inc\Controllers
 */
class LessonProgressController extends AjaxController {

	public function __construct(
		private readonly LessonPlayerCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::MarkStepProgress, $this->callbacks ),
		);
	}
}
