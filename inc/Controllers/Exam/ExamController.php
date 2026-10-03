<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\AjaxHook;
use Inc\Core\BaseController;
use Inc\Callbacks\Exam\ExamCallbacks;
use Inc\Callbacks\Exam\LearnerExamCallbacks;

class ExamController extends BaseController implements ServiceInterface {

	public function __construct(
		private ExamCallbacks $examCallbacks,
		private LearnerExamCallbacks $learnerCallbacks
	) {}

	public function register(): void {
		$this->registerAdminActions();
		$this->registerLearnerActions();
	}

	private function registerAdminActions(): void {
		add_action( 'wp_ajax_' . AjaxHook::SaveExamEvent->jsAction(), [ $this->examCallbacks, 'ajaxSaveExamEvent' ] );
		add_action( 'wp_ajax_' . AjaxHook::PublishExamEvent->jsAction(), [ $this->examCallbacks, 'ajaxPublishExamEvent' ] );
		add_action( 'wp_ajax_' . AjaxHook::SaveExamSession->jsAction(), [ $this->examCallbacks, 'ajaxSaveExamSession' ] );
		add_action( 'wp_ajax_' . AjaxHook::CreateExamRegistration->jsAction(), [ $this->examCallbacks, 'ajaxCreateExamRegistration' ] );
		add_action( 'wp_ajax_' . AjaxHook::CancelExamRegistration->jsAction(), [ $this->examCallbacks, 'ajaxCancelExamRegistration' ] );
		add_action( 'wp_ajax_' . AjaxHook::TransferExamRegistration->jsAction(), [ $this->examCallbacks, 'ajaxTransferExamRegistration' ] );
		add_action( 'wp_ajax_' . AjaxHook::GetExamParticipations->jsAction(), [ $this->examCallbacks, 'ajaxGetExamParticipations' ] );
		add_action( 'wp_ajax_' . AjaxHook::GetExamSessions->jsAction(), [ $this->examCallbacks, 'ajaxGetExamSessions' ] );
		add_action( 'wp_ajax_' . AjaxHook::ApproveExamAttempt->jsAction(), [ $this->examCallbacks, 'ajaxApproveExamAttempt' ] );
		add_action( 'wp_ajax_' . AjaxHook::CreateExamResultLink->jsAction(), [ $this->examCallbacks, 'ajaxCreateExamResultLink' ] );
		add_action( 'wp_ajax_' . AjaxHook::CreateExamGuestLink->jsAction(), [ $this->examCallbacks, 'ajaxCreateExamGuestLink' ] );
	}

	private function registerLearnerActions(): void {
		add_action( 'wp_ajax_' . AjaxHook::GetLearnerExams->jsAction(), [ $this->learnerCallbacks, 'ajaxGetLearnerExams' ] );
		add_action( 'wp_ajax_nopriv_' . AjaxHook::GetLearnerExams->jsAction(), [ $this->learnerCallbacks, 'ajaxGetLearnerExams' ] );

		add_action( 'wp_ajax_' . AjaxHook::RegisterForExam->jsAction(), [ $this->learnerCallbacks, 'ajaxRegisterForExam' ] );
		add_action( 'wp_ajax_' . AjaxHook::ChangeExamRegistration->jsAction(), [ $this->learnerCallbacks, 'ajaxChangeExamRegistration' ] );
		add_action( 'wp_ajax_' . AjaxHook::CancelExamRegistration->jsAction(), [ $this->learnerCallbacks, 'ajaxCancelExamRegistration' ] );
	}
}
