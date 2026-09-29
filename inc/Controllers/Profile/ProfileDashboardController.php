<?php

declare( strict_types=1 );

namespace Inc\Controllers\Profile;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Profile\DashboardCallbacks;
use Inc\Callbacks\Profile\TeacherCoursesCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Регистрирует AJAX «Главной» кабинета (Эпик 6) и страницы курса преподавателя (Tasks.md З4).
 *
 * @package Inc\Controllers\Profile
 */
class ProfileDashboardController extends AjaxController {

	public function __construct(
		private readonly DashboardCallbacks      $callbacks,
		private readonly TeacherCoursesCallbacks $courses,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetProfileDashboard, $this->callbacks ),
			array( AjaxHook::GetTaughtCourseProgram, $this->courses ),
		);
	}
}
