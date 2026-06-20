<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Assessment\AttemptCallbacks;
use Inc\Callbacks\Assessment\GradeAttemptCallbacks;
use Inc\Enums\Wp\AjaxHook;

class AssessmentController extends AjaxController {

	public function __construct(
		private readonly AttemptCallbacks      $attemptCallbacks,
		private readonly GradeAttemptCallbacks $gradeCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return [
			[ AjaxHook::StartAttempt,      $this->attemptCallbacks ],
			[ AjaxHook::SaveAttemptAnswer,  $this->attemptCallbacks ],
			[ AjaxHook::SubmitAttempt,      $this->attemptCallbacks ],
			[ AjaxHook::GetAttemptResult,   $this->attemptCallbacks ],
			[ AjaxHook::GradeAttempt,       $this->gradeCallbacks ],
		];
	}
}
