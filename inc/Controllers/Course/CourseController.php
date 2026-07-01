<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\CloneCallbacks;
use Inc\Callbacks\Course\CourseBuilderCallbacks;
use Inc\Callbacks\Course\CourseCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class CourseController
 *
 * Регистрирует AJAX-хуки конструктора курса (селектор уроков + Stepik-билдер).
 *
 * @package Inc\Controllers
 */
class CourseController extends AjaxController {

	public function __construct(
		private readonly CourseCallbacks        $callbacks,
		private readonly CourseBuilderCallbacks $builderCallbacks,
		private readonly CloneCallbacks         $cloneCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetCourseLessonCandidates, $this->callbacks ),
			array( AjaxHook::CreateCourseDraft,         $this->builderCallbacks ),
			array( AjaxHook::GetCourseBuilder,          $this->builderCallbacks ),
			array( AjaxHook::SaveCourseStructure,       $this->builderCallbacks ),
			array( AjaxHook::CreateLessonInModule,      $this->builderCallbacks ),
			array( AjaxHook::DuplicateLessonInModule,   $this->builderCallbacks ),
			array( AjaxHook::UpdateLessonMeta,          $this->builderCallbacks ),
			array( AjaxHook::SaveCourseMeta,            $this->builderCallbacks ),
			array( AjaxHook::CloneLesson,               $this->cloneCallbacks ),
			array( AjaxHook::CloneWork,                 $this->cloneCallbacks ),
			array( AjaxHook::CloneAssessment,           $this->cloneCallbacks ),
			array( AjaxHook::CloneCourse,               $this->cloneCallbacks ),
			array( AjaxHook::ForkLessonForGroup,        $this->cloneCallbacks ),
		);
	}
}
