<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AttemptResultService;
use Inc\Services\Assessment\AttemptService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class AttemptCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly AttemptService       $attemptService,
		private readonly PersonRepository     $personRepository,
		private readonly AttemptResultService $resultService,
	) {
		parent::__construct();
	}

	public function ajaxStartAttempt(): void {
		Nonce::StartAttempt->verify();

		$assessmentId  = $this->requireInt( 'assessment_id' );
		$groupId       = $this->sanitizeInt( 'group_id' ) ?: null;
		$groupLessonId = $this->sanitizeInt( 'group_lesson_id' ) ?: null;

		$userId = get_current_user_id();
		$person = $this->personRepository->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		try {
			$attempt = $this->attemptService->start( $person->id, $assessmentId, $groupId, $groupLessonId );
			$this->success( [
				'attempt_id'  => $attempt->id,
				'deadline_at' => $attempt->deadlineAt,
				'status'      => $attempt->status->value,
			] );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxSaveAttemptAnswer(): void {
		Nonce::StartAttempt->verify();

		$attemptId  = $this->requireInt( 'attempt_id' );
		$taskId     = $this->requireInt( 'task_id' );
		$answerText = $this->sanitizeHtml( 'answer_text' );

		$userId = get_current_user_id();
		$person = $this->personRepository->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		try {
			$this->attemptService->saveAnswer( $attemptId, $taskId, $answerText, $person->id );
			$this->success( [] );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxSubmitAttempt(): void {
		Nonce::SubmitAttempt->verify();

		$attemptId = $this->requireInt( 'attempt_id' );

		$userId = get_current_user_id();
		$person = $this->personRepository->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		try {
			$attempt = $this->attemptService->submit( $attemptId, $person->id );
			$perTask = $this->resultService->studentPerTask( $attempt->id, $person->id );
			$this->success( array(
				'status'      => $attempt->status->value,
				'total_score' => $attempt->totalScore,
				'max_score'   => $attempt->maxScore,
				'per_task'    => $perTask,
			) );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxGetAttemptResult(): void {
		Nonce::StartAttempt->verify();

		$attemptId = $this->requireInt( 'attempt_id' );

		$userId = get_current_user_id();
		$person = $this->personRepository->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		try {
			$result = $this->attemptService->getResult( $attemptId, $person->id );
			$this->success( $result );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}
