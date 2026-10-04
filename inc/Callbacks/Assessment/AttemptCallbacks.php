<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptResultService;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX попыток станции и générique-плеера.
 *
 * Попытка курса идёт через {@see AttemptService}; официальная попытка экзамена — только через
 * {@see ExamAttemptService} (блокировка участия, дедлайн, outbox). Выбор делает `examContext()`:
 * по самой попытке, а не по признаку в запросе, чтобы экзамен нельзя было сохранить или сдать «по-старому».
 * Итог и разбор в ответах отдаются только по политике раскрытия ({@see AttemptService::isRevealed()}).
 */
class AttemptCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly AttemptService          $attemptService,
		private readonly PersonRepository        $personRepository,
		private readonly AttemptResultService    $resultService,
		private readonly AssessmentManager       $assessments,
		private readonly AssessmentAccessPolicy  $access,
		private readonly ExamAttemptService      $examAttempts,
	) {
		parent::__construct();
	}

	public function ajaxStartAttempt(): void {
		Nonce::StartAttempt->verify();

		$assessmentId       = $this->requireInt( 'assessment_id' );
		$groupId            = $this->sanitizeInt( 'group_id' ) ?: null;
		$groupLessonId      = $this->sanitizeInt( 'group_lesson_id' ) ?: null;
		$examRegistrationId = $this->sanitizeInt( 'exam_registration_id' );

		$person = $this->currentPerson();
		if ( null === $person ) {
			return;
		}

		try {
			$attempt = $examRegistrationId > 0
				? $this->examAttempts->start( $this->examAttempts->contextForStudent( get_current_user_id(), $examRegistrationId ) )
				: $this->attemptService->start( $person->id, $assessmentId, $groupId, $groupLessonId );

			$this->success( [
				'attempt_id'  => $attempt->id,
				'deadline_at' => $attempt->deadlineAt,
				'status'      => $attempt->status->value,
			] );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxSaveAttemptAnswer(): void {
		Nonce::StartAttempt->verify();

		$attemptId  = $this->requireInt( 'attempt_id' );
		$taskId     = $this->requireInt( 'task_id' );
		$answerText = $this->sanitizeAnswerText( 'answer_text' );

		$person = $this->currentPerson();
		if ( null === $person ) {
			return;
		}

		try {
			$examContext = $this->examContext( $attemptId );
			if ( null !== $examContext ) {
				$this->examAttempts->saveAnswer( $examContext, $attemptId, $taskId, $answerText );
			} else {
				$this->attemptService->saveAnswer( $attemptId, $taskId, $answerText, $person->id );
			}
			$this->success( [] );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxSubmitAttempt(): void {
		Nonce::SubmitAttempt->verify();

		$attemptId = $this->requireInt( 'attempt_id' );

		$person = $this->currentPerson();
		if ( null === $person ) {
			return;
		}

		try {
			$examContext = $this->examContext( $attemptId );
			$attempt     = null !== $examContext
				? $this->examAttempts->submit( $examContext, $attemptId )
				: $this->attemptService->submit( $attemptId, $person->id );

			$this->success( $this->submissionPayload( $attempt, $person->id ) );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxGetAttemptResult(): void {
		Nonce::StartAttempt->verify();

		$attemptId = $this->requireInt( 'attempt_id' );

		$person = $this->currentPerson();
		if ( null === $person ) {
			return;
		}

		try {
			$examContext = $this->examContext( $attemptId );
			$result      = null !== $examContext
				? $this->examAttempts->result( $examContext, $attemptId )
				: $this->attemptService->getResult( $attemptId, $person->id );

			$this->success( $result );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
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

	/**
	 * Ответ на сдачу: статус всегда; итог и разбор — только если политика раскрытия их разрешает.
	 * Экзаменная попытка ученика до утверждения не получает ни `total_score`, ни `per_task`.
	 *
	 * @return array<string, mixed>
	 */
	private function submissionPayload( AttemptDTO $attempt, int $personId ): array {
		$payload = array( 'status' => $attempt->status->value );

		if ( $this->attemptService->isRevealed( $attempt ) ) {
			$payload += array(
				'total_score' => $attempt->totalScore,
				'max_score'   => $attempt->maxScore,
				'per_task'    => $this->resultService->studentPerTask( $attempt->id, $personId ),
			);
		}

		return $payload;
	}

	/**
	 * Контекст экзамена для попытки или null, если это попытка курса. Чужая экзаменная попытка — `CodedException`.
	 *
	 * @throws CodedException
	 */
	private function examContext( int $attemptId ): ?AttemptContext {
		return $this->examAttempts->contextForAttempt( get_current_user_id(), $attemptId );
	}

	/** Профиль текущего пользователя; нет профиля — ответ-ошибка уже отправлен, вернётся null. */
	private function currentPerson(): ?PersonDTO {
		$person = $this->personRepository->findByWpUserId( get_current_user_id() );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return null;
		}
		return $person;
	}
}
