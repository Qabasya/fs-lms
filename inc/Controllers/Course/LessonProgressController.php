<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\LessonPlayerCallbacks;
use Inc\Callbacks\Course\SubmitTaskAnswerCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class LessonProgressController
 *
 * Регистрирует AJAX-хуки пошагового плеера урока и сдачи интерактивных заданий.
 *
 * @package Inc\Controllers
 */
class LessonProgressController extends AjaxController {

	public function __construct(
		private readonly LessonPlayerCallbacks      $callbacks,
		private readonly SubmitTaskAnswerCallbacks  $taskCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::MarkStepProgress, $this->callbacks ),
			array( AjaxHook::SubmitTaskAnswer, $this->taskCallbacks ),
		);
	}
}
