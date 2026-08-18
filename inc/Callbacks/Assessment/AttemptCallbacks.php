<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptResultService;
use Inc\Services\Assessment\AttemptService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class AttemptCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly AttemptService          $attemptService,
		private readonly PersonRepository        $personRepository,
		private readonly AttemptResultService    $resultService,
		private readonly AssessmentManager       $assessments,
		private readonly AssessmentAccessPolicy  $access,
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
		$answerText = $this->sanitizeAnswerText( 'answer_text' );

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

	/**
	 * Результат по ответам, присланным прямо из формы (T-preview-4): предпросмотр
	 * générique-контрольной у автора/методиста/куратора занятия — попытки в БД
	 * нет и не будет (AttemptPageService::buildPreview()), поэтому оценка идёт
	 * по накопленному в этой вкладке, тем же алгоритмом, что и настоящая сдача
	 * (см. AutoGradeService::evaluate(), AttemptResultService::previewPerTask()).
	 * Аналог PreviewResultCallbacks станции КЕГЭ, только для générique-плеера.
	 */
	public function ajaxPreviewAttemptResult(): void {
		Nonce::StartAttempt->verify();

		$assessmentId = $this->requireInt( 'assessment_id' );

		$userId = get_current_user_id();
		// Тот же гейт, что открывает саму страницу вхолостую (AssessmentPageController):
		// без него любой залогиненный мог бы дёрнуть эндпоинт с чужим assessment_id
		// и получить вердикт/баллы по контрольной, до которой доступа нет.
		if ( ! $userId || ! $this->access->canPreview( $userId, $assessmentId ) ) {
			$this->error( 'Доступ запрещён.' );
			return;
		}

		$assessment = $this->assessments->get( $assessmentId );
		if ( ! $assessment ) {
			$this->error( 'Контрольная не найдена.' );
			return;
		}

		$rawByTask = array();
		foreach ( $this->unslashArray( 'answers' ) as $taskId => $value ) {
			$taskId = absint( $taskId );
			if ( $taskId > 0 ) {
				$rawByTask[ $taskId ] = $this->sanitizeAnswerTextValue( $value );
			}
		}

		$perTask    = $this->resultService->previewPerTask( $assessment, $rawByTask );
		$totalScore = array_sum( array_map( static fn( array $t ): float => (float) ( $t['score'] ?? 0.0 ), $perTask ) );
		$totalMax   = array_sum( array_map( static fn( array $t ): float => (float) ( $t['max_score'] ?? 0.0 ), $perTask ) );

		$this->success( array(
			'total_score' => $totalScore,
			'max_score'   => $totalMax,
			'per_task'    => $perTask,
		) );
	}
}
