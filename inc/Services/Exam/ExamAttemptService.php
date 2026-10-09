<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\AttemptInputDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AttemptService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Попытка экзамена вне курса: старт, сохранение, сдача, автоистечение, продление (6.1–6.2).
 *
 * Весь экзаменный путь попытки идёт здесь; старый `AttemptService::start()` через занятие курса
 * для экзамена не подходит, а старый путь сохранения и сдачи экзаменную попытку отвергает
 * ({@see AttemptService}). Допуск проверяется на сервере в момент каждого запроса, а старт, сохранение
 * ответа, сдача, автоистечение, отмена записи и неявка сериализуются блокировкой одной и той же строки участия.
 *
 * **Первый оператор каждой транзакции — блокировка участия** (`FOR UPDATE`), а идентификатор участия определяется до
 * `START TRANSACTION`: под REPEATABLE READ снимок данных создаёт первое же обычное чтение транзакции, и перечитывание
 * попытки «под блокировкой» после более раннего чтения вернуло бы устаревшее состояние.
 *
 * Время в `assessment_attempts` — местное, в `exam_sessions` — UTC; переводит только `ExamTime`.
 */
class ExamAttemptService {

	use TransactionRunner;

	private const MAX_EXTENSION_MINUTES = 120;

	public function __construct(
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamEventRepository $events,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly PersonRepository $persons,
		private readonly AssessmentManager $assessments,
		private readonly AttemptService $attemptService,
		private readonly ExamNoShowService $noShow,
		private readonly ExamFormatRegistry $formats,
		private readonly ExamAccessGuard $accessGuard,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
		private readonly GuestSessionService $guestSessions,
	) {}

	/**
	 * Контекст ученика: запись существует, а её участие принадлежит участнику с `person_id`
	 * этого пользователя. Чужая запись отвечает так же, как несуществующая.
	 *
	 * @throws CodedException
	 */
	public function contextForStudent( int $wpUserId, int $registrationId ): AttemptContext {
		$registration  = $this->registrations->find( $registrationId );
		$participation = null !== $registration ? $this->participations->find( $registration->participationId ) : null;
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		$person        = $this->persons->findByWpUserId( $wpUserId );

		if ( null === $registration || null === $participation || null === $participant || null === $person
			|| $participant->personId !== $person->id ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		return new AttemptContext( ExamAudience::Student, $participation->id, $registration->id, $person->id, $wpUserId );
	}

	/**
	 * Контекст гостя по куке входа: `personId = null`, `wpUserId = null`, аудитория `guest`. Нет сессии, она отозвана или запись не действует — null.
	 * Гость не пользователь WordPress; единственная личность — гостевая сессия ({@see GuestSessionService::current()}).
	 */
	public function contextForGuest(): ?AttemptContext {
		return $this->guestSessions->current();
	}

	/**
	 * Старт попытки. Повторный вызов (обновление страницы, двойной клик) возвращает ту же попытку.
	 *
	 * @throws CodedException
	 */
	public function start( AttemptContext $ctx ): AttemptDTO {
		$result = $this->inTransactionWithRetry( fn (): AttemptDTO|true => $this->startLocked( $ctx ) );

		// Неявка зафиксирована внутри транзакции и должна сохраниться, поэтому отказ — после COMMIT.
		if ( true === $result ) {
			throw new CodedException( ErrorCode::ExamNotOpen, 'Время начала истекло.' );
		}

		return $result;
	}

	/** @return AttemptDTO|true `true` — время старта истекло, неявка проставлена. */
	private function startLocked( AttemptContext $ctx ): AttemptDTO|true {
		$isGuest = ExamAudience::Guest === $ctx->audience;
		if ( ! $isGuest && null === $ctx->personId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		$participation = $this->participations->findForUpdate( $ctx->participationId );
		if ( null === $participation || $isGuest !== ( ExamAudience::Guest->value === $participation->audience ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}
		// Гость садится за станцию только после допуска сотрудника на площадке.
		if ( $isGuest && null === $participation->admittedAt ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		if ( null !== $participation->currentAttemptId ) {
			$existing = $this->attempts->find( $participation->currentAttemptId );
			if ( null !== $existing ) {
				return $existing;
			}
		}

		$registration = $this->registrations->find( $ctx->registrationId );
		if ( null === $registration || $registration->participationId !== $participation->id
			|| 1 !== $registration->activeSlot || ExamRegistrationStatus::Confirmed->value !== $registration->status ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		$session = $this->sessions->find( $registration->sessionId );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event
			|| ExamSessionStatus::Cancelled->value === $session->status
			|| ExamEventStatus::Cancelled->value === $event->status ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		$nowUtc = $this->time->nowUtc();
		if ( $nowUtc < $session->scheduledAt ) {
			throw new CodedException( ErrorCode::ExamNotOpen, 'Экзамен ещё не начался.' );
		}
		if ( $nowUtc >= $session->plannedEndAt ) {
			$this->noShow->markMissedLocked( $participation, $registration, $session );
			return true;
		}

		$startedAt = $this->time->nowLocal();
		// У гостя нет Person, а значит и другой его активной попытки: «одна на участие» держит уникальный индекс.
		if ( ! $isGuest && null !== $this->attempts->findAnyActive( (int) $ctx->personId, $startedAt ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сначала завершите начатую работу.' );
		}

		$assessmentId = $session->assessmentId;
		$deadlineAt   = $this->time->addMinutes( $startedAt, $this->durationMinutes( $event->snapshotFor( $assessmentId ), $assessmentId ) );

		// Плановый конец сеанса дедлайн не обрезает: начавший получает полную длительность.
		// Лимит попыток работы здесь не проверяется: официальная попытка одна на участие
		// (шаг выше + уникальный индекс).
		$attemptId = $this->attempts->create( new AttemptInputDTO(
			assessmentId       : $assessmentId,
			studentPersonId    : $ctx->personId,
			groupId            : null,
			attemptNumber      : $isGuest ? 1 : $this->attempts->nextAttemptNumber( (int) $ctx->personId, $assessmentId ),
			startedAt          : $startedAt,
			deadlineAt         : $deadlineAt,
			groupLessonId      : null,
			examParticipationId: $participation->id,
			examRegistrationId : $registration->id,
		) );
		if ( 0 === $attemptId ) {
			throw new \RuntimeException( 'Не удалось создать попытку экзамена.' );
		}

		$this->participations->setCurrentAttempt( $participation->id, $attemptId );
		$this->sessions->markFirstStarted( $session->id, $nowUtc );
		$this->outbox->add(
			ExamOutboxEvent::AttemptStarted,
			'participation',
			$participation->id,
			$participation->version,
			array(
				'attempt_id'      => $attemptId,
				'registration_id' => $registration->id,
				'session_id'      => $session->id,
			)
		);

		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt ) {
			throw new \RuntimeException( 'Созданная попытка экзамена не найдена.' );
		}
		if ( $isGuest ) {
			// Сессия живёт до личного дедлайна + время на просмотр результата.
			$this->guestSessions->extendForAttempt( $participation->id, $this->time->toUtc( $attempt->deadlineAt ) );
		}
		return $attempt;
	}

	/**
	 * Длительность — из снимка проведения; нет снимка — из формата варианта.
	 *
	 * @param array<string, mixed>|null $snapshot
	 */
	private function durationMinutes( ?array $snapshot, int $assessmentId ): int {
		$fromSnapshot = (int) ( $snapshot['duration_minutes'] ?? 0 );
		if ( $fromSnapshot > 0 ) {
			return $fromSnapshot;
		}

		$assessment = $this->assessments->get( $assessmentId );
		$format     = null !== $assessment ? $this->formats->for( $assessment->kind ) : null;
		if ( null === $format || $format->durationMinutes <= 0 ) {
			throw new CodedException( ErrorCode::ExamAccess, 'У варианта экзамена не задана длительность.' );
		}
		return $format->durationMinutes;
	}


	/**
	 * Завершает просроченную попытку: `submitted_at = deadline_at` (а не момент срабатывания тика),
	 * затем обычная автопроверка по сохранённым ответам. Идемпотентно.
	 *
	 * @return bool true — попытка завершена этим вызовом.
	 */
	public function finalizeExpired( int $attemptId ): bool {
		// Участие определяем до транзакции: внутри неё первым оператором должна быть блокировка.
		$participationId = $this->attempts->find( $attemptId )?->examParticipationId;
		if ( null === $participationId ) {
			return false;
		}

		return (bool) $this->inTransactionWithRetry( function () use ( $attemptId, $participationId ): bool {
			$participation = $this->participations->findForUpdate( $participationId );
			$attempt       = $this->attempts->find( $attemptId );
			if ( null === $participation || null === $attempt
				|| AttemptStatus::InProgress !== $attempt->status
				|| ! $attempt->isExpired( $this->time->nowLocal() ) ) {
				return false;
			}

			$this->attemptService->submitFor( $attempt, $attempt->deadlineAt );
			$this->outbox->add(
				ExamOutboxEvent::AttemptSubmitted,
				'participation',
				$participation->id,
				$participation->version,
				array(
					'attempt_id' => $attempt->id,
					'auto'       => true,
				)
			);
			return true;
		} );
	}

	/**
	 * Сохранение ответа. Состояние и дедлайн проверяются и ответ пишется под той же блокировкой участия,
	 * что у сдачи и автоистечения: без неё автосохранение, проверившее `in_progress`, могло дописать ответ
	 * уже после сдачи и оценивания, и итог перестал бы соответствовать сохранённому ответу.
	 *
	 * @throws CodedException
	 */
	public function saveAnswer( AttemptContext $ctx, int $attemptId, int $taskId, string $text ): void {
		// Просроченную попытку завершает ленивый путь — со своей транзакцией, поэтому до открытия нашей.
		$this->activeAttemptOf( $ctx, $attemptId );

		$this->inTransactionWithRetry( function () use ( $ctx, $attemptId, $taskId, $text ): void {
			$this->lockParticipation( $ctx );
			$this->attemptService->saveAnswerFor( $this->writableAttempt( $ctx, $attemptId ), $taskId, $text );
		} );
	}

	/**
	 * @throws CodedException
	 */
	public function submit( AttemptContext $ctx, int $attemptId ): AttemptDTO {
		$this->activeAttemptOf( $ctx, $attemptId );

		return $this->inTransactionWithRetry( function () use ( $ctx, $attemptId ): AttemptDTO {
			$participation = $this->lockParticipation( $ctx );
			$attempt       = $this->writableAttempt( $ctx, $attemptId );

			$submitted = $this->attemptService->submitFor( $attempt );
			if ( ExamAudience::Guest->value === $participation->audience ) {
				$this->guestSessions->closeAfterSubmit( $participation->id );
			}
			$this->outbox->add(
				ExamOutboxEvent::AttemptSubmitted,
				'participation',
				$participation->id,
				$participation->version,
				array(
					'attempt_id' => $attempt->id,
					'auto'       => false,
				)
			);
			return $submitted;
		} );
	}

	/**
	 * Результат своей попытки: просроченная завершается здесь же, затем применяется общая политика раскрытия.
	 *
	 * @return array{attempt: AttemptDTO, answers: list<\Inc\DTO\Assessment\AttemptAnswerDTO>}
	 *
	 * @throws CodedException
	 */
	public function result( AttemptContext $ctx, int $attemptId ): array {
		$attempt = $this->ownedAttempt( $ctx, $attemptId );
		if ( AttemptStatus::InProgress === $attempt->status && $attempt->isExpired( $this->time->nowLocal() ) ) {
			$this->finalizeExpired( $attempt->id );
		}

		return null === $ctx->personId
			? $this->attemptService->getExamResult( $attemptId )
			: $this->attemptService->getResult( $attemptId, $ctx->personId );
	}

	/**
	 * Контекст ученика для попытки: null — попытка не экзаменная (обычный путь курса).
	 * Чужая экзаменная попытка отвечает как несуществующая запись.
	 *
	 * @throws CodedException
	 */
	public function contextForAttempt( int $wpUserId, int $attemptId ): ?AttemptContext {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt || ! $attempt->isExam() ) {
			return null;
		}
		if ( null === $attempt->examRegistrationId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}

		return $this->contextForStudent( $wpUserId, $attempt->examRegistrationId );
	}

	/**
	 * Состояние станции по записи: вариант и текущая попытка. Просроченная попытка завершается здесь же
	 * (ленивый путь 6.2.3), поэтому страница станции никогда не показывает идущую попытку с истёкшим дедлайном.
	 *
	 * @return array{assessment_id: int, attempt: ?AttemptDTO}|null null — записи нет, либо она закрыта и попытки не было.
	 */
	public function stationState( AttemptContext $ctx ): ?array {
		$registration = $this->registrations->find( $ctx->registrationId );
		$session      = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
		if ( null === $registration || null === $session || $registration->participationId !== $ctx->participationId ) {
			return null;
		}

		$participation = $this->participations->find( $ctx->participationId );
		$attempt       = null !== $participation && null !== $participation->currentAttemptId
			? $this->attempts->find( $participation->currentAttemptId )
			: null;

		if ( null !== $attempt && AttemptStatus::InProgress === $attempt->status && $attempt->isExpired( $this->time->nowLocal() ) ) {
			$this->finalizeExpired( $attempt->id );
			$attempt = $this->attempts->find( $attempt->id );
		}

		// Закрытая запись (отмена, неявка, перенос) без попытки станцию не открывает.
		if ( null === $attempt && 1 !== $registration->activeSlot ) {
			return null;
		}

		return array(
			'assessment_id' => $attempt->assessmentId ?? $session->assessmentId,
			'attempt'       => $attempt,
		);
	}

	/**
	 * Продление личного дедлайна сотрудником с правом на проведение.
	 *
	 * @throws CodedException
	 */
	public function extend( int $actorUserId, int $attemptId, int $minutes, string $reason ): AttemptDTO {
		if ( $minutes < 1 || $minutes > self::MAX_EXTENSION_MINUTES ) {
			throw new CodedException( ErrorCode::ExamConflict, sprintf( 'Продлить можно на срок от 1 до %d минут.', self::MAX_EXTENSION_MINUTES ) );
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите причину продления.' );
		}

		// Участие определяем до транзакции: внутри неё первым оператором должна быть блокировка.
		$participationId = $this->attempts->find( $attemptId )?->examParticipationId;
		if ( null === $participationId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Попытка не найдена.' );
		}

		return $this->inTransactionWithRetry( function () use ( $actorUserId, $attemptId, $minutes, $reason, $participationId ): AttemptDTO {
			$participation = $this->participations->findForUpdate( $participationId );
			$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
			if ( null === $participation || null === $event || ! $this->accessGuard->canManageSubject( $actorUserId, $event->subjectKey ) ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Попытка не найдена.' );
			}

			$attempt = $this->attempts->find( $attemptId );
			if ( null === $attempt || AttemptStatus::InProgress !== $attempt->status ) {
				throw new CodedException( ErrorCode::ExamStarted, 'Попытка уже завершена.' );
			}

			$newDeadline = $this->time->addMinutes( $attempt->deadlineAt, $minutes );
			if ( ! $this->attempts->update( $attempt->id, array( 'deadline_at' => $newDeadline ) ) ) {
				throw new \RuntimeException( 'Не удалось продлить попытку экзамена.' );
			}
			$this->outbox->add(
				ExamOutboxEvent::AttemptExtended,
				'participation',
				$participation->id,
				$participation->version,
				array(
					'attempt_id'    => $attempt->id,
					'minutes'       => $minutes,
					'reason'        => $reason,
					'actor_user_id' => $actorUserId,
					'deadline_at'   => $newDeadline,
				)
			);

			$updated = $this->attempts->find( $attempt->id );
			if ( null === $updated ) {
				throw new \RuntimeException( 'Попытка экзамена не найдена после продления.' );
			}
			if ( ExamAudience::Guest->value === $participation->audience ) {
				$this->guestSessions->extendForAttempt( $participation->id, $this->time->toUtc( $updated->deadlineAt ) );
			}
			return $updated;
		} );
	}

	/**
	 * Попытка существует и принадлежит участию контекста; чужая отвечает как несуществующая.
	 *
	 * @throws CodedException
	 */
	private function ownedAttempt( AttemptContext $ctx, int $attemptId ): AttemptDTO {
		$attempt = $this->attempts->find( $attemptId );
		if ( null === $attempt || $attempt->examParticipationId !== $ctx->participationId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Попытка не найдена.' );
		}
		return $attempt;
	}

	/**
	 * Блокирует участие контекста: общая точка сериализации сохранения, сдачи, автоистечения, старта и неявки.
	 *
	 * @throws CodedException Участия нет.
	 */
	private function lockParticipation( AttemptContext $ctx ): ExamParticipationDTO {
		$participation = $this->participations->findForUpdate( $ctx->participationId );
		if ( null === $participation ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}
		return $participation;
	}

	/**
	 * Попытка, в которую сейчас можно писать: свежее состояние под блокировкой участия, статус `in_progress`,
	 * дедлайн не наступил. Общее для сохранения ответа и сдачи — одни условия и одни тексты.
	 *
	 * @throws CodedException
	 */
	private function writableAttempt( AttemptContext $ctx, int $attemptId ): AttemptDTO {
		$attempt = $this->ownedAttempt( $ctx, $attemptId );

		if ( AttemptStatus::InProgress !== $attempt->status ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Попытка уже завершена.' );
		}
		if ( $attempt->isExpired( $this->time->nowLocal() ) ) {
			// Дедлайн наступил между проверкой до транзакции и блокировкой: завершит тик или следующий запрос.
			throw new CodedException( ErrorCode::ExamStarted, 'Время попытки истекло.' );
		}

		return $attempt;
	}

	/**
	 * Попытка принадлежит участию контекста и ещё идёт; просроченную — завершает и отказывает.
	 * Своя транзакция только у `finalizeExpired()`: вызывать вне чужой транзакции.
	 *
	 * @throws CodedException
	 */
	private function activeAttemptOf( AttemptContext $ctx, int $attemptId ): AttemptDTO {
		$attempt = $this->ownedAttempt( $ctx, $attemptId );

		if ( AttemptStatus::InProgress !== $attempt->status ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Попытка уже завершена.' );
		}

		if ( $attempt->isExpired( $this->time->nowLocal() ) ) {
			// Ленивый путь: просроченная попытка завершается и без cron.
			$this->finalizeExpired( $attempt->id );
			throw new CodedException( ErrorCode::ExamStarted, 'Время попытки истекло.' );
		}

		return $attempt;
	}
}
