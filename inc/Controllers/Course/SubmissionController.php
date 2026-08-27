<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Course\BatchSubmissionCallbacks;
use Inc\Callbacks\Course\GradingCallbacks;
use Inc\Callbacks\Course\ReviewQueueCallbacks;
use Inc\Callbacks\Course\SubmissionCallbacks;
use Inc\Enums\Wp\AjaxHook;

class SubmissionController extends AjaxController {

	public function __construct(
		private readonly SubmissionCallbacks      $submissionCallbacks,
		private readonly GradingCallbacks         $gradingCallbacks,
		private readonly BatchSubmissionCallbacks $batchCallbacks,
		private readonly ReviewQueueCallbacks     $reviewQueueCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::UploadAnswerFile,      $this->submissionCallbacks ),
			array( AjaxHook::SaveGrade,             $this->gradingCallbacks ),
			array( AjaxHook::ReturnSubmission,      $this->gradingCallbacks ),
			array( AjaxHook::GetWorkDetail,         $this->gradingCallbacks ),
			array( AjaxHook::GetWorkAttemptHistory, $this->gradingCallbacks ),
			array( AjaxHook::ResetAttempts,         $this->gradingCallbacks ),
			array( AjaxHook::SubmitBatchWork,       $this->batchCallbacks ),
			array( AjaxHook::GradeBatchTask,        $this->batchCallbacks ),
			array( AjaxHook::GetPendingWorks,       $this->reviewQueueCallbacks ),
			array( AjaxHook::GetWorkSubmissions,    $this->reviewQueueCallbacks ),
		);
	}
}
