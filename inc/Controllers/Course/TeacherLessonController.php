<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\TeacherLessonCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class TeacherLessonController
 *
 * Регистрирует AJAX-хуки преподавателя над уроком занятия своей группы (Этап 3):
 * состав шагов (COW-форк) и read-only банк кандидатов/превью.
 *
 * @package Inc\Controllers
 */
class TeacherLessonController extends AjaxController {

	public function __construct(
		private readonly TeacherLessonCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetGroupLessonSteps,   $this->callbacks ),
			array( AjaxHook::SaveGroupLessonSteps,  $this->callbacks ),
			array( AjaxHook::TeacherStepCandidates, $this->callbacks ),
			array( AjaxHook::TeacherTaskPreview,    $this->callbacks ),
			array( AjaxHook::TeacherRefPreview,     $this->callbacks ),
		);
	}
}
