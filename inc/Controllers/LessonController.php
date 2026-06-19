<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Course\LessonCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class LessonController
 *
 * Регистрирует AJAX-хуки конструктора бакетов урока.
 *
 * @package Inc\Controllers
 */
class LessonController extends AjaxController {

	public function __construct(
		private readonly LessonCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetLessonWorkCandidates, $this->callbacks ),
			array( AjaxHook::GetLessonArticles,       $this->callbacks ),
			array( AjaxHook::CreateLessonDraft,        $this->callbacks ),
			array( AjaxHook::GetStepCandidates,        $this->callbacks ),
			array( AjaxHook::SaveLessonSteps,          $this->callbacks ),
			array( AjaxHook::MoveLessonStep,           $this->callbacks ),
			array( AjaxHook::CreateTaskDraft,          $this->callbacks ),
			array( AjaxHook::CreateAssessmentDraft,    $this->callbacks ),
			array( AjaxHook::CreateArticleDraft,       $this->callbacks ),
		);
	}
}
