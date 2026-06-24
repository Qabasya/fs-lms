<?php

declare( strict_types=1 );

namespace Inc\Controllers\Assessment;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Assessment\AssessmentAuthorCallbacks;
use Inc\Callbacks\Assessment\AttemptCallbacks;
use Inc\Callbacks\Assessment\GradeAttemptCallbacks;
use Inc\Callbacks\Assessment\ScoreMapCallbacks;
use Inc\Enums\Wp\AjaxHook;

class AssessmentController extends AjaxController {

	public function __construct(
		private readonly AssessmentAuthorCallbacks $authorCallbacks,
		private readonly AttemptCallbacks          $attemptCallbacks,
		private readonly GradeAttemptCallbacks     $gradeCallbacks,
		private readonly ScoreMapCallbacks         $scoreMapCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return [
			[ AjaxHook::SaveAssessmentItems, $this->authorCallbacks ],
			[ AjaxHook::GetTaskPreview,            $this->authorCallbacks ],
			[ AjaxHook::CreateAssessmentTaskDraft, $this->authorCallbacks ],
			[ AjaxHook::StartAttempt,        $this->attemptCallbacks ],
			[ AjaxHook::SaveAttemptAnswer,  $this->attemptCallbacks ],
			[ AjaxHook::SubmitAttempt,      $this->attemptCallbacks ],
			[ AjaxHook::GetAttemptResult,   $this->attemptCallbacks ],
			[ AjaxHook::GradeAttempt,       $this->gradeCallbacks ],
			[ AjaxHook::ParseScoreMap,      $this->scoreMapCallbacks ],
			[ AjaxHook::CopyScoreMap,       $this->scoreMapCallbacks ],
		];
	}
}
