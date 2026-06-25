<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\AttemptInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

class AttemptService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly AutoGradeService            $autoGrade,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
		private readonly AssessmentAccessPolicy      $access,
	) {}

	/**
	 * Старт попытки.
	 *
	 * @throws \RuntimeException Если исчерпан лимит попыток или дублирующий INSERT (двойной клик).
	 */
	public function start( int $studentPersonId, int $assessmentId, ?int $groupId ): AttemptDTO {
		$assessment = $this->assessments->get( $assessmentId );
		if ( ! $assessment ) {
			throw new \InvalidArgumentException( "Контрольная {$assessmentId} не найдена." );
		}

		if ( ! $this->access->canAccess( $studentPersonId, $assessmentId ) ) {
			throw new \RuntimeException( 'Нет доступа к этой контрольной.' );
		}

		if ( $assessment->attemptsAllowed > 0 ) {
			$used = $this->attempts->countByAssessmentAndStudent( $assessmentId, $studentPersonId );
			if ( $used >= $assessment->attemptsAllowed ) {
				throw new \RuntimeException( 'Исчерпан лимит попыток.' );
			}
		}

		$now          = $this->clock->now();
		$deadlineAt   = $assessment->timeLimit > 0
			? date( 'Y-m-d H:i:s', strtotime( $now ) + $assessment->timeLimit * 60 )
			: date( 'Y-m-d H:i:s', strtotime( $now ) + 100 * YEAR_IN_SECONDS );
		$attemptNumber = $this->attempts->nextAttemptNumber( $studentPersonId, $assessmentId );

		$dto = new AttemptInputDTO(
			assessmentId    : $assessmentId,
			studentPersonId : $studentPersonId,
			groupId         : $groupId,
			attemptNumber   : $attemptNumber,
			startedAt       : $now,
			deadlineAt      : $deadlineAt,
		);

		$id = $this->attempts->create( $dto );
		if ( $id === 0 ) {
			throw new \RuntimeException( 'Не удалось создать попытку (возможно, гонка двойного клика).' );
		}

		$attempt = $this->attempts->find( $id );
		assert( $attempt !== null );

		$this->dispatcher->dispatch(
			LogEvent::AttemptStarted,
			new LearningEvent(
				event      : LogEvent::AttemptStarted,
				actorUserId: $studentPersonId,
				groupId    : $groupId,
				entityType : 'attempt',
				entityId   : (string) $id,
				isPublic   : false,
			)
		);

		return $attempt;
	}

	/**
	 * Сохранение ответа (autosave / промежуточная запись).
	 *
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 * @throws \RuntimeException Если попытка просрочена или уже завершена.
	 */
	public function saveAnswer( int $attemptId, int $taskId, string $answerText, int $studentPersonId ): void {
		$attempt = $this->requireActiveAttempt( $attemptId, $studentPersonId );

		$this->answers->upsert( $attempt->id, $taskId, [ 'answer_text' => $answerText ] );
	}

	/**
	 * Финальная сдача контрольной.
	 *
	 * @throws \InvalidArgumentException Если попытка не найдена или не принадлежит студенту.
	 * @throws \RuntimeException Если попытка просрочена.
	 */
	public function submit( int $attemptId, int $studentPersonId ): AttemptDTO {
		$attempt = $this->requireActiveAttempt( $attemptId, $studentPersonId );

		$now = $this->clock->now();
		$this->attempts->update( $attempt->id, [
			'status'       => AttemptStatus::Submitted->value,
			'submitted_at' => $now,
		] );

		$submitted = $this->attempts->find( $attempt->id );
		assert( $submitted !== null );

		$this->dispatcher->dispatch(
			LogEvent::AttemptSubmitted,
			new LearningEvent(
				event      : LogEvent::AttemptSubmitted,
				actorUserId: $studentPersonId,
				groupId    : $attempt->groupId,
				entityType : 'attempt',
				entityId   : (string) $attempt->id,
				isPublic   : false,
			)
		);

		return $this->autoGrade->gradeAttempt( $submitted );
	}

	/**
	 * Ленивая проверка и проставление expired.
	 *
	 * @return bool true если попытка была просрочена и помечена expired.
	 */
	public function expireIfOverdue( int $attemptId ): bool {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->status !== AttemptStatus::InProgress ) {
			return false;
		}

		if ( ! $attempt->isExpired( $this->clock->now() ) ) {
			return false;
		}

		$this->attempts->update( $attempt->id, [ 'status' => AttemptStatus::Expired->value ] );

		$this->dispatcher->dispatch(
			LogEvent::AttemptExpired,
			new LearningEvent(
				event      : LogEvent::AttemptExpired,
				actorUserId: $attempt->studentPersonId,
				groupId    : $attempt->groupId,
				entityType : 'attempt',
				entityId   : (string) $attempt->id,
				isPublic   : false,
			)
		);

		return true;
	}

	/**
	 * Результат попытки для отображения.
	 *
	 * @return array{attempt: AttemptDTO, answers: AttemptAnswerDTO[]}
	 */
	public function getResult( int $attemptId, int $studentPersonId ): array {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		$this->expireIfOverdue( $attemptId );

		$attempt = $this->attempts->find( $attemptId );
		assert( $attempt !== null );

		return [
			'attempt' => $attempt,
			'answers' => $this->answers->listByAttempt( $attemptId ),
		];
	}

	/** Валидирует, что попытка активна и принадлежит студенту. */
	private function requireActiveAttempt( int $attemptId, int $studentPersonId ): AttemptDTO {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt || $attempt->studentPersonId !== $studentPersonId ) {
			throw new \InvalidArgumentException( 'Попытка не найдена.' );
		}

		if ( $attempt->status !== AttemptStatus::InProgress ) {
			throw new \RuntimeException( 'Попытка уже завершена.' );
		}

		if ( $this->expireIfOverdue( $attemptId ) ) {
			throw new \RuntimeException( 'Время попытки истекло.' );
		}

		return $attempt;
	}
}
