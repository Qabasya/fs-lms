<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Shared\CodedException;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Утверждение результатов экзамена (8.5): ученик видит итог только после явного утверждения сотрудником.
 *
 * **Одна работа — одна транзакция.** Первый оператор — блокировка участия (оно же сериализует старт, сдачу и неявку); состояние
 * работы перечитывается только после неё. Поэтому параллельные утверждения одной попытки дают ровно одно событие `AttemptApproved`:
 * второй видит `approved_at` и отвечает `already_approved` без записи в outbox.
 *
 * **Массовое утверждение** идёт поштучно: ошибка или пропуск одной работы остальные не откатывает (частичный успех — норма).
 * Утверждение гостя не нужно: его результат выдаётся без этого шага.
 */
class ExamApprovalService {

	use TransactionRunner;

	public const STATUS_APPROVED = 'approved';
	public const STATUS_SKIPPED  = 'skipped';

	public const REASON_NOT_FOUND        = 'not_found';
	public const REASON_NO_ACCESS        = 'no_access';
	public const REASON_GUEST            = 'guest';
	public const REASON_NOT_SUBMITTED    = 'not_submitted';
	public const REASON_PENDING_REVIEW   = 'pending_review';
	public const REASON_STALE            = 'stale';
	public const REASON_ALREADY_APPROVED = 'already_approved';
	public const REASON_ERROR            = 'error';

	private const REASON_LABELS = array(
		self::REASON_NOT_FOUND        => 'Работа не найдена',
		self::REASON_NO_ACCESS        => 'Нет доступа к проведению',
		self::REASON_GUEST            => 'Гостю утверждение не требуется',
		self::REASON_NOT_SUBMITTED    => 'Работа не сдана',
		self::REASON_PENDING_REVIEW   => 'Проверка не завершена',
		self::REASON_STALE            => 'Работу уже изменил другой проверяющий',
		self::REASON_ALREADY_APPROVED => 'Уже утверждена',
		self::REASON_ERROR            => 'Не удалось утвердить',
	);

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository $answers,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamEventRepository $events,
		private readonly ExamAccessGuard $guard,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
		private readonly AutoGradeService $autoGrade,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Утверждает одну работу.
	 *
	 * @param int $expectedVersion `result_version`, который видел проверяющий; расхождение — пропуск `stale`.
	 *
	 * @return array{status: 'approved'|'skipped', reason?: string}
	 */
	public function approve( int $actorUserId, int $attemptId, int $expectedVersion ): array {
		// Участие определяется до транзакции: внутри неё первым оператором должна быть блокировка.
		$peek            = $this->attempts->find( $attemptId );
		$participationId = $peek?->examParticipationId;
		if ( null === $peek || null === $participationId ) {
			return $this->skipped( self::REASON_NOT_FOUND );
		}

		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event ) {
			return $this->skipped( self::REASON_NOT_FOUND );
		}
		if ( ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			return $this->skipped( self::REASON_NO_ACCESS );
		}
		if ( ExamAudience::Guest->value === $participation->audience ) {
			return $this->skipped( self::REASON_GUEST );
		}

		return $this->inTransactionWithRetry( function () use ( $actorUserId, $attemptId, $participationId, $expectedVersion ): array {
			$locked  = $this->participations->findForUpdate( $participationId );
			$attempt = $this->attempts->find( $attemptId );
			if ( null === $locked || null === $attempt || $attempt->examParticipationId !== $participationId ) {
				return $this->skipped( self::REASON_NOT_FOUND );
			}

			if ( AttemptStatus::InProgress === $attempt->status ) {
				return $this->skipped( self::REASON_NOT_SUBMITTED );
			}
			if ( $attempt->isApproved() ) {
				return $this->skipped( self::REASON_ALREADY_APPROVED );
			}
			if ( $this->answers->hasPendingAnswers( $attempt->id ) ) {
				return $this->skipped( self::REASON_PENDING_REVIEW );
			}
			if ( $attempt->resultVersion !== $expectedVersion ) {
				return $this->skipped( self::REASON_STALE );
			}

			if ( ! $this->attempts->approve( $attempt->id, $actorUserId, $this->time->nowLocal() ) ) {
				throw new \RuntimeException( 'Не удалось утвердить результат экзамена.' );
			}
			$this->outbox->add(
				ExamOutboxEvent::AttemptApproved,
				'participation',
				$locked->id,
				$locked->version,
				array(
					'attempt_id'       => $attempt->id,
					'participation_id' => $locked->id,
					'result_version'   => $attempt->resultVersion,
				)
			);

			return array( 'status' => self::STATUS_APPROVED );
		} );
	}

	/**
	 * Массовое утверждение: каждая работа в своей транзакции, ошибка одной не откатывает остальные.
	 *
	 * @param list<array{attempt_id: int, result_version: int}> $items
	 *
	 * @return array{approved: int, skipped: list<array{attempt_id: int, reason: string, reason_label: string}>}
	 */
	public function approveMany( int $actorUserId, array $items ): array {
		$approved = 0;
		$skipped  = array();

		foreach ( $items as $item ) {
			$attemptId = (int) $item['attempt_id'];
			try {
				$result = $this->approve( $actorUserId, $attemptId, (int) $item['result_version'] );
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamApproval', $e, array( 'attempt_id' => $attemptId ), true );
				$result = $this->skipped( self::REASON_ERROR );
			}

			if ( self::STATUS_APPROVED === $result['status'] ) {
				++$approved;
				continue;
			}
			// Уже утверждённая — не ошибка и не пропуск, о котором надо кричать, но в списке она видна с причиной.
			$reason    = (string) ( $result['reason'] ?? self::REASON_ERROR );
			$skipped[] = array(
				'attempt_id'   => $attemptId,
				'reason'       => $reason,
				'reason_label' => self::REASON_LABELS[ $reason ] ?? self::REASON_LABELS[ self::REASON_ERROR ],
			);
		}

		return array( 'approved' => $approved, 'skipped' => $skipped );
	}

	/** @return array{status: 'skipped', reason: string} */
	private function skipped( string $reason ): array {
		return array( 'status' => self::STATUS_SKIPPED, 'reason' => $reason );
	}

	/**
	 * Исправление утверждённого результата (8.6): баллы по заданиям, причина обязательна, версия защищает от двух проверяющих.
	 *
	 * Ответ ученика (`answer_text`) не изменяется никогда — меняются только балл, отметка «верно», комментарий и проверяющий.
	 * Итог пересчитывается тем же `AutoGradeService::finalize()`. Исправление сразу действует на опубликованный результат:
	 * повторного утверждения не нужно. Старые и новые значения уходят в журнал изменений и в событие `ResultCorrected`.
	 *
	 * @param list<array{task_id: int, score: float, feedback?: string}> $changes
	 *
	 * @throws CodedException `ExamConflict` — причина/балл/состояние; `ExamStale` — версия устарела; `ExamAccess` — нет права.
	 */
	public function correct( int $actorUserId, int $attemptId, array $changes, string $reason, int $expectedVersion ): AttemptDTO {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите причину исправления.' );
		}
		if ( array() === $changes ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Нет изменений для сохранения.' );
		}

		$peek            = $this->attempts->find( $attemptId );
		$participationId = $peek?->examParticipationId;
		$participation   = null !== $participationId ? $this->participations->find( $participationId ) : null;
		$event           = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participationId || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Работа не найдена.' );
		}

		$result = $this->inTransactionWithRetry( function () use ( $actorUserId, $attemptId, $participationId, $changes, $reason, $expectedVersion ): array {
			$locked  = $this->participations->findForUpdate( $participationId );
			$attempt = $this->attempts->find( $attemptId );
			if ( null === $locked || null === $attempt || $attempt->examParticipationId !== $participationId ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Работа не найдена.' );
			}
			if ( ! $attempt->isApproved() ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Работа не утверждена: исправлять можно только утверждённый результат.' );
			}
			if ( ! $this->attempts->bumpResultVersion( $attemptId, $expectedVersion ) ) {
				throw new CodedException( ErrorCode::ExamStale, 'Работу уже изменил другой проверяющий. Обновите страницу.' );
			}

			$now      = $this->time->nowLocal();
			$journal  = array();
			foreach ( $changes as $change ) {
				$answer = $this->answers->findByAttemptAndTask( $attemptId, (int) $change['task_id'] );
				if ( null === $answer ) {
					throw new CodedException( ErrorCode::ExamConflict, 'Задание не найдено в работе.' );
				}

				$max   = (float) ( $answer->maxScore ?? 0.0 );
				$score = (float) $change['score'];
				if ( $score < 0.0 || $score > $max ) {
					throw new CodedException( ErrorCode::ExamConflict, sprintf( 'Балл должен быть от 0 до %s.', $this->num( $max ) ) );
				}

				$row = array(
					'score'             => $score,
					'is_correct'        => $score >= $max ? 1 : 0,
					'graded_by_user_id' => $actorUserId,
					'graded_at'         => $now,
				);
				if ( isset( $change['feedback'] ) && '' !== trim( (string) $change['feedback'] ) ) {
					$row['grader_note'] = trim( (string) $change['feedback'] );
				}
				// Критерии описывали прежний балл: оставлять их рядом с новым баллом значит показывать ученику несогласованные числа.
				if ( null !== $answer->criteriaScores ) {
					$row['criteria_scores'] = null;
				}

				if ( ! $this->answers->upsert( $attemptId, (int) $change['task_id'], $row ) ) {
					throw new \RuntimeException( 'Не удалось записать исправленный балл.' );
				}
				$journal[] = array( 'task_id' => (int) $change['task_id'], 'old' => (float) ( $answer->score ?? 0.0 ), 'new' => $score );
			}

			$updated = $this->autoGrade->finalize( $attempt );
			$version = $expectedVersion + 1;
			$this->outbox->add(
				ExamOutboxEvent::ResultCorrected,
				'participation',
				$locked->id,
				$locked->version,
				array(
					'attempt_id'       => $attemptId,
					'participation_id' => $locked->id,
					'reason'           => $reason,
					'result_version'   => $version,
					'old_total'        => $attempt->totalScore,
					'new_total'        => $updated->totalScore,
					'changes'          => $journal,
				)
			);

			return array( 'attempt' => $updated, 'old_total' => $attempt->totalScore, 'journal' => $journal );
		} );

		$this->audit( $actorUserId, $attemptId, $reason, $result['journal'], $result['old_total'], $result['attempt']->totalScore );

		return $result['attempt'];
	}

	/**
	 * Строка журнала изменений: причина и пары «было → стало» по заданиям и итогу. Метка журнала ограничена 255 знаками —
	 * при длинном перечне обрезается; полный перечень остаётся в событии `ResultCorrected`.
	 *
	 * @param list<array{task_id: int, old: float, new: float}> $journal
	 */
	private function audit( int $actorUserId, int $attemptId, string $reason, array $journal, ?float $oldTotal, ?float $newTotal ): void {
		$pairs = array_map(
			fn ( array $c ): string => sprintf( '№%d: %s→%s', $c['task_id'], $this->num( $c['old'] ), $this->num( $c['new'] ) ),
			$journal
		);
		$label = sprintf(
			'Итог %s→%s; %s. Причина: %s',
			$this->num( (float) $oldTotal ),
			$this->num( (float) $newTotal ),
			implode( ', ', $pairs ),
			$reason
		);

		$this->logEvents->dispatch(
			LogEvent::ExamResultCorrected,
			new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::ExamAttempt, $attemptId, mb_substr( $label, 0, 255 ) )
		);
	}

	private function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
