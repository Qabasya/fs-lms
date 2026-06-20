<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Course\GradingCallbacks;
use Inc\Callbacks\Course\SubmissionCallbacks;
use Inc\Enums\Wp\AjaxHook;

class SubmissionController extends AjaxController {

	public function __construct(
		private readonly SubmissionCallbacks $submissionCallbacks,
		private readonly GradingCallbacks    $gradingCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SubmitWork,          $this->submissionCallbacks ),
			array( AjaxHook::GetMySubmissions,    $this->submissionCallbacks ),
			array( AjaxHook::SaveGrade,           $this->gradingCallbacks ),
			array( AjaxHook::ReturnSubmission,    $this->gradingCallbacks ),
			array( AjaxHook::GetGroupSubmissions, $this->gradingCallbacks ),
			array( AjaxHook::GetGradebook,        $this->gradingCallbacks ),
		);
	}
}
