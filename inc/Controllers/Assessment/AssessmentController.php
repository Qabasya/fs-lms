<?php

declare( strict_types=1 );

namespace Inc\Controllers\Assessment;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Assessment\AssessmentAuthorCallbacks;
use Inc\Callbacks\Assessment\AttemptCallbacks;
use Inc\Callbacks\Assessment\GradeAttemptCallbacks;
use Inc\Enums\Wp\AjaxHook;

class AssessmentController extends AjaxController {

	public function __construct(
		private readonly AssessmentAuthorCallbacks $authorCallbacks,
		private readonly AttemptCallbacks          $attemptCallbacks,
		private readonly GradeAttemptCallbacks     $gradeCallbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return [
			[ AjaxHook::SaveAssessmentItems, $this->authorCallbacks ],
			[ AjaxHook::GetTaskPreview,            $this->authorCallbacks ],
			[ AjaxHook::GetRefPreview,             $this->authorCallbacks ],
			[ AjaxHook::CreateAssessmentTaskDraft, $this->authorCallbacks ],
			[ AjaxHook::PreviewAttemptResult, $this->attemptCallbacks ],
			[ AjaxHook::GradeAttempt,       $this->gradeCallbacks ],
			[ AjaxHook::ApproveAttempt,     $this->gradeCallbacks ],
		];
	}

	/**
	 * Станция экзамена для гостя без входа в WordPress: старт, сохранение, сдача и результат своей попытки.
	 * Защита — nonce и гостевая сессия: без неё коллбек отвечает `X-ACCESS`, попытку курса по номеру гость не откроет.
	 */
	protected function publicAjaxActions(): array {
		return [
			[ AjaxHook::StartAttempt,       $this->attemptCallbacks ],
			[ AjaxHook::SaveAttemptAnswer,  $this->attemptCallbacks ],
			[ AjaxHook::SubmitAttempt,      $this->attemptCallbacks ],
			[ AjaxHook::GetAttemptResult,   $this->attemptCallbacks ],
		];
	}
}
