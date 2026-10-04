<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Callbacks\Exam\ExamEventCallbacks;
use Inc\Callbacks\Exam\ExamSourceCallbacks;
use Inc\Callbacks\Exam\LearnerExamCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * AJAX экзаменов. Все действия — для вошедших пользователей (ученик, родитель, сотрудник):
 * доступ к данным проверяют сами коллбеки (нонс + владелец данных / право + область).
 */
class ExamController extends AjaxController {

	public function __construct(
		private readonly LearnerExamCallbacks $learner,
		private readonly ExamEventCallbacks $events,
		private readonly ExamSourceCallbacks $sources,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetLearnerExams, $this->learner ),
			array( AjaxHook::RegisterForExam, $this->learner ),
			array( AjaxHook::ChangeExamRegistration, $this->learner ),
			array( AjaxHook::CancelExamRegistration, $this->learner ),
			array( AjaxHook::GetExamReview, $this->learner ),

			array( AjaxHook::GetExamPlan, $this->events ),
			array( AjaxHook::SaveExamSession, $this->events ),
			array( AjaxHook::DeleteExamSession, $this->events ),
			array( AjaxHook::SaveExamEvent, $this->events ),
			array( AjaxHook::PublishExamEvent, $this->events ),
			array( AjaxHook::CancelExamEvent, $this->events ),

			array( AjaxHook::GetExamSources, $this->sources ),
			array( AjaxHook::SaveExamSource, $this->sources ),
			array( AjaxHook::IssueExamSourceLink, $this->sources ),
			array( AjaxHook::ReissueExamSourceLink, $this->sources ),
			array( AjaxHook::RevokeExamSourceLink, $this->sources ),
			array( AjaxHook::ToggleExamSource, $this->sources ),
		);
	}
}
