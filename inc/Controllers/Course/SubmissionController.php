<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\BatchSubmissionCallbacks;
use Inc\Callbacks\Course\GradingCallbacks;
use Inc\Callbacks\Course\SubmissionCallbacks;
use Inc\Enums\Wp\AjaxHook;

class SubmissionController extends AjaxController {

	public function __construct(
		private readonly SubmissionCallbacks      $submissionCallbacks,
		private readonly GradingCallbacks         $gradingCallbacks,
		private readonly BatchSubmissionCallbacks $batchCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SubmitWork,          $this->submissionCallbacks ),
			array( AjaxHook::UploadAnswerFile,    $this->submissionCallbacks ),
			array( AjaxHook::GetMySubmissions,    $this->submissionCallbacks ),
			array( AjaxHook::SaveGrade,           $this->gradingCallbacks ),
			array( AjaxHook::ReturnSubmission,    $this->gradingCallbacks ),
			array( AjaxHook::GetGroupSubmissions, $this->gradingCallbacks ),
			array( AjaxHook::GetGradebook,        $this->gradingCallbacks ),
			array( AjaxHook::GetWorkDetail,       $this->gradingCallbacks ),
			array( AjaxHook::ResetAttempts,       $this->gradingCallbacks ),
			array( AjaxHook::SubmitBatchWork,     $this->batchCallbacks ),
			array( AjaxHook::GradeBatchTask,      $this->batchCallbacks ),
		);
	}
}
