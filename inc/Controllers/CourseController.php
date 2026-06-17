<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Course\CourseCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class CourseController
 *
 * Регистрирует AJAX-хуки конструктора курса.
 *
 * @package Inc\Controllers
 */
class CourseController extends AjaxController {

	public function __construct(
		private readonly CourseCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetCourseLessonCandidates, $this->callbacks ),
		);
	}
}
