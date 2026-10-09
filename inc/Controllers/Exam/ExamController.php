<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Callbacks\Exam\ExamConductCallbacks;
use Inc\Callbacks\Exam\ExamEventCallbacks;
use Inc\Callbacks\Exam\ExamResultCallbacks;
use Inc\Callbacks\Exam\ExamSourceCallbacks;
use Inc\Callbacks\Exam\ExamStatsCallbacks;
use Inc\Callbacks\Exam\GuestApplicationCallbacks;
use Inc\Callbacks\Exam\GuestEntryCallbacks;
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
		private readonly ExamConductCallbacks $conduct,
		private readonly ExamResultCallbacks $results,
		private readonly ExamStatsCallbacks $stats,
		private readonly GuestApplicationCallbacks $guest,
		private readonly GuestEntryCallbacks $guestEntry,
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
			array( AjaxHook::MoveExamSession, $this->events ),
			array( AjaxHook::CancelExamSession, $this->events ),
			array( AjaxHook::CancelExamEvent, $this->events ),

			array( AjaxHook::GetExamConduct, $this->conduct ),
			array( AjaxHook::CancelExamRegistrationByStaff, $this->conduct ),
			array( AjaxHook::TransferExamRegistration, $this->conduct ),
			array( AjaxHook::ExtendExamAttempt, $this->conduct ),
			array( AjaxHook::MarkExamArrival, $this->conduct ),
			array( AjaxHook::AddExamGuestOnSite, $this->conduct ),
			array( AjaxHook::IssueExamGuestPayLink, $this->conduct ),
			array( AjaxHook::AdmitExamGuest, $this->conduct ),
			array( AjaxHook::IssueExamEntryLink, $this->conduct ),
			array( AjaxHook::IssueExamResultLink, $this->conduct ),
			array( AjaxHook::RevokeExamResultLink, $this->conduct ),
			array( AjaxHook::ApproveExamAttempts, $this->conduct ),
			array( AjaxHook::CorrectExamResult, $this->conduct ),
			array( AjaxHook::ExportExamParticipants, $this->conduct ),
			array( AjaxHook::GetExamPrintList, $this->conduct ),

			array( AjaxHook::GetExamResults, $this->results ),
			array( AjaxHook::GetExamStats, $this->stats ),

			array( AjaxHook::GetExamSources, $this->sources ),
			array( AjaxHook::SaveExamSource, $this->sources ),
			array( AjaxHook::IssueExamSourceLink, $this->sources ),
			array( AjaxHook::ReissueExamSourceLink, $this->sources ),
			array( AjaxHook::RevokeExamSourceLink, $this->sources ),
			array( AjaxHook::ToggleExamSource, $this->sources ),
		);
	}

	/** Гость (и вошедший ученик на публичной форме): запись по ссылке школы и проверка статуса заказа. Защита — nonce `ExamGuest` и ключ заказа. */
	protected function publicAjaxActions(): array {
		return array(
			array( AjaxHook::SubmitExamGuestApplication, $this->guest ),
			array( AjaxHook::CheckExamApplicationStatus, $this->guest ),
			array( AjaxHook::EndExamGuestSession, $this->guestEntry ),
		);
	}
}
