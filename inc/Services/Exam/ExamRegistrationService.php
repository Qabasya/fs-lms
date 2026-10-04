<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamOperationKeyDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\DTO\Exam\RegistrationResultDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamOperationKeyRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Запись на экзамен: запись, перенос, отмена, история (3.1–3.3).
 *
 * Порядок блокировок един для всех операций: участник (там, где проверяется пересечение по времени) →
 * участие → сеансы по возрастанию ID. Строка участника — единственная общая точка сериализации одного
 * человека между разными проведениями: блокировки участий разных проведений его не упорядочивают.
 * **Первый оператор каждой транзакции — блокирующее чтение** (`FOR UPDATE`) строки, которая сериализует операцию,
 * а всё, что нужно для её выбора, читается ДО `START TRANSACTION`. Под REPEATABLE READ снимок данных создаёт первое же
 * обычное чтение транзакции: прочитанное раньше блокировки не увидит записи, зафиксированные другой транзакцией,
 * пока вы ждали, и проверка пересечений пропустит двойную запись.
 * Каждая операция — одна транзакция; отказ по бизнес-правилу — `CodedException` внутри неё,
 * поэтому откат не оставляет побочных эффектов (место не занято, outbox не записан).
 */
class ExamRegistrationService {

	use TransactionRunner;

	private const REQUEST_KEY_MAX_LENGTH = 64;
	private const OPERATION_TTL_MINUTES  = 1440;

	private const OP_REGISTER = 'register';
	private const OP_CHANGE   = 'change';
	private const OP_CANCEL   = 'cancel';

	/** Предупреждение в {@see RegistrationResultDTO::$warnings}: сеанс пересекается с занятием ученика. */
	public const WARNING_LESSON_OVERLAP = 'lesson_overlap';

	/** Длительность занятия без `ends_at` — как в `RoomRepository::isBusy()`. */
	private const DEFAULT_LESSON_MINUTES = 60;

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamOperationKeyRepository $operationKeys,
		private readonly ExamAudienceResolver $audience,
		private readonly ExamAccessGuard $accessGuard,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
		private readonly ExamGuestApplicationRepository $guestApplications,
		private readonly GroupLessonRepository $lessons,
	) {}

	/**
	 * Запись ученика на сеанс.
	 *
	 * @throws CodedException
	 */
	public function register( int $personId, int $sessionId, string $requestKey ): RegistrationResultDTO {
		$this->assertRequestKey( $requestKey );

		$session = $this->sessions->find( $sessionId );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event || ExamEventStatus::Published->value !== $event->status ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись на этот экзамен закрыта.' );
		}

		if ( ! $this->audience->isEligible( $personId, $event->subjectKey ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен для этого ученика.' );
		}

		$participantId = $this->participants->getOrCreateForPerson( $personId );
		$warnings      = $this->lessonOverlapWarning( $personId, $session ) ? array( self::WARNING_LESSON_OVERLAP ) : array();

		return $this->registerParticipant( $participantId, ExamAudience::Student, $sessionId, $requestKey, null, $warnings );
	}

	/**
	 * Ядро записи: им пользуются `register()`, перенос сотрудником, конвертация брони гостя и стенд.
	 *
	 * @param int|null $actorUserId Сотрудник, оформляющий запись; null — участник записывается сам.
	 * @param string[] $warnings    Предупреждения для ответа (не отменяют запись); хранятся вместе с результатом для повтора по ключу.
	 *
	 * @throws CodedException
	 */
	public function registerParticipant( int $participantId, ExamAudience $audience, int $sessionId, string $requestKey, ?int $actorUserId, array $warnings = array() ): RegistrationResultDTO {
		$this->assertRequestKey( $requestKey );

		return $this->inTransactionWithRetry( fn (): RegistrationResultDTO => $this->registerLocked( $participantId, $audience, $sessionId, $requestKey, $actorUserId, false, null, $warnings ) );
	}

	/**
	 * Подтверждение брони гостя: то же, что {@see registerParticipant()}, но **место уже занято** бронью (или самим вызывающим)
	 * и окно записи не проверяется — гость оплатил, пока бронь была действующей.
	 *
	 * Транзакцию **не открывает**: вызывается из `ExamHoldService::convert()` внутри его транзакции, первым оператором которой
	 * стала блокировка заявки. Порядок блокировок здесь тот же: участник → участие → сеанс.
	 *
	 * @param int      $sourceId    Источник приглашения; записывается в участие.
	 * @param int|null $recordedBy  Сотрудник, подтвердивший вручную: записывается в запись, правила записи не меняет.
	 *
	 * @throws CodedException
	 */
	public function confirmHeld( int $participantId, int $sessionId, string $requestKey, int $sourceId, ?int $recordedBy = null ): RegistrationResultDTO {
		$this->assertRequestKey( $requestKey );

		return $this->registerLocked( $participantId, ExamAudience::Guest, $sessionId, $requestKey, null, true, $sourceId, array(), $recordedBy );
	}

	/**
	 * Подтверждение оплаты после того, как бронь истекла и место освобождено: место занимается заново и **по обычным правилам записи**
	 * (проведение опубликовано, сеанс открыт и не начался, окно записи открыто, есть место). Любой отказ — `CodedException`, брошенный
	 * до записи в базу и без утечки места: вызывающий переводит заявку в «оплачено, нужна помощь» и продолжает свою транзакцию.
	 * Транзакцию не открывает — как {@see confirmHeld()}.
	 *
	 * @throws CodedException
	 */
	public function confirmLate( int $participantId, int $sessionId, string $requestKey, int $sourceId, ?int $recordedBy = null ): RegistrationResultDTO {
		$this->assertRequestKey( $requestKey );

		return $this->registerLocked( $participantId, ExamAudience::Guest, $sessionId, $requestKey, null, false, $sourceId, array(), $recordedBy );
	}

	/**
	 * Тело записи без транзакции: блокировки участник → участие → сеанс, идемпотентность, правила, место, запись, событие.
	 *
	 * @param bool     $seatHeld   Место уже занято (бронь): `occupySeat()` и проверки окна записи пропускаются.
	 * @param string[] $warnings
	 * @param int|null $recordedBy Кто оформил, если это не `$actorUserId` (подтверждение оплаты сотрудником).
	 */
	private function registerLocked( int $participantId, ExamAudience $audience, int $sessionId, string $requestKey, ?int $actorUserId, bool $seatHeld, ?int $sourceId, array $warnings, ?int $recordedBy = null ): RegistrationResultDTO {
		// Блокировка 1: участник (сериализует проверку пересечений между проведениями).
		$this->lockParticipant( $participantId );

		$peek = $this->sessions->find( $sessionId );
		if ( null === $peek ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс не найден.' );
		}

		// Блокировка 2: участие.
		$participation = $this->participations->getOrCreateLocked( $peek->eventId, $participantId, $audience );

		// Идемпотентность — под блокировкой участия: два одинаковых запроса сериализуются.
		$replayed = $this->findOperation( $participantId, self::OP_REGISTER, $requestKey, (string) $sessionId );
		if ( null !== $replayed ) {
			return RegistrationResultDTO::fromArray( (array) json_decode( (string) $replayed->resultRef, true ) )->asReplayed();
		}

		// Блокировка 3: сеанс.
		$session = $this->sessions->findForUpdate( $sessionId );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс не найден.' );
		}

		$nowUtc = $this->time->nowUtc();
		$this->assertCanRegister( $event, $session, $participation, $nowUtc, null !== $actorUserId, ! $seatHeld );

		// Место занимается одним условным UPDATE: вместимость проверяет сама база.
		if ( ! $seatHeld && ! $this->sessions->occupySeat( $session->id ) ) {
			throw $this->noSeatsError( $session->id );
		}

		try {
			$registrationId = $this->insertConfirmed( $participation->id, $session->id, $requestKey, $actorUserId ?? $recordedBy, $nowUtc );
		} catch ( CodedException $e ) {
			// Отказ после занятого места: место возвращается, чтобы вызывающий мог продолжить транзакцию (подтверждение оплаты).
			if ( ! $seatHeld ) {
				$this->sessions->releaseSeat( $session->id );
			}
			throw $e;
		}
		$this->participations->setActiveRegistration( $participation->id, $registrationId );
		if ( null !== $sourceId ) {
			$this->participations->setSource( $participation->id, $sourceId );
		}
		$this->outbox->add(
			ExamOutboxEvent::RegistrationConfirmed,
			'registration',
			$registrationId,
			1,
			array(
				'event_id'         => $session->eventId,
				'session_id'       => $session->id,
				'participation_id' => $participation->id,
				'audience'         => $audience->value,
			)
		);

		$result = new RegistrationResultDTO(
			registrationId : $registrationId,
			participationId: $participation->id,
			sessionId      : $session->id,
			status         : ExamRegistrationStatus::Confirmed,
			freeSeats      : $this->freeSeats( $session->id ),
			warnings       : $warnings,
		);
		$this->rememberOperation( $participantId, self::OP_REGISTER, $requestKey, (string) $sessionId, json_encode( $result->toArray() ) ?: '' );

		return $result;
	}

	/**
	 * Перенос ученика на другой сеанс (до начала своего сеанса). Неудачный перенос сохраняет старую запись.
	 *
	 * @param int|null $expectedVersion Версия участия, которую видит вкладка; расхождение — `ExamStale`.
	 *
	 * @throws CodedException
	 */
	public function change( int $personId, int $newSessionId, string $requestKey, ?int $expectedVersion = null ): RegistrationResultDTO {
		$this->assertRequestKey( $requestKey );

		$peek        = $this->sessions->find( $newSessionId );
		$participant = $this->participants->findByPersonId( $personId );
		if ( null === $peek ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс не найден.' );
		}
		if ( null === $participant ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
		}

		return $this->inTransactionWithRetry( function () use ( $participant, $peek, $newSessionId, $requestKey, $expectedVersion ): RegistrationResultDTO {
			$participation = $this->lockParticipation( $participant->id, $peek->eventId );
			if ( null === $participation ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
			}

			$replayed = $this->findOperation( $participation->participantId, self::OP_CHANGE, $requestKey, (string) $newSessionId );
			if ( null !== $replayed ) {
				return RegistrationResultDTO::fromArray( (array) json_decode( (string) $replayed->resultRef, true ) )->asReplayed();
			}

			$this->assertFreshVersion( $participation, $expectedVersion );

			$current = $this->registrations->findActive( $participation->id );
			if ( null === $current ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
			}

			$result = $this->moveRegistration( $participation, $current, $newSessionId, false, null, null );
			$this->rememberOperation( $participation->participantId, self::OP_CHANGE, $requestKey, (string) $newSessionId, json_encode( $result->toArray() ) ?: '' );

			return $result;
		} );
	}

	/**
	 * Самоотмена до начала своего сеанса и при отсутствии попытки. Повтор с тем же ключом — без ошибки
	 * и без второго освобождения места.
	 *
	 * @param int|null $expectedVersion Версия участия, которую видит вкладка; расхождение — `ExamStale`.
	 *
	 * @throws CodedException
	 */
	public function cancelBySelf( int $personId, int $eventId, string $requestKey, ?int $expectedVersion = null ): void {
		$this->assertRequestKey( $requestKey );

		$participant = $this->participants->findByPersonId( $personId );
		if ( null === $participant ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
		}

		$this->inTransactionWithRetry( function () use ( $participant, $eventId, $requestKey, $expectedVersion ): void {
			$participation = $this->lockParticipation( $participant->id, $eventId );
			if ( null === $participation ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
			}

			if ( null !== $this->findOperation( $participation->participantId, self::OP_CANCEL, $requestKey, (string) $eventId ) ) {
				return;
			}

			$this->assertFreshVersion( $participation, $expectedVersion );

			$registration = $this->registrations->findActive( $participation->id );
			if ( null === $registration ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
			}

			$sessions = $this->sessions->lockInOrder( array( $registration->sessionId ) );
			$session  = $sessions[ $registration->sessionId ] ?? null;
			if ( null === $session ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
			}

			$nowUtc = $this->time->nowUtc();
			if ( null !== $participation->currentAttemptId ) {
				throw new CodedException( ErrorCode::ExamStarted, 'Экзамен уже начат или сдан.' );
			}
			if ( $nowUtc >= $session->scheduledAt ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже начался: отменить запись самостоятельно нельзя.' );
			}

			$this->closeRegistration( $participation, $registration, $session, ExamRegistrationStatus::Cancelled, $nowUtc, null, null, 'self' );
			$this->rememberOperation( $participation->participantId, self::OP_CANCEL, $requestKey, (string) $eventId, 'ok' );
		} );
	}

	/**
	 * Отмена сотрудником с причиной: разрешена и после начала сеанса, пока попытка не начата.
	 *
	 * @throws CodedException
	 */
	public function cancelByStaff( int $actorUserId, int $registrationId, string $reason ): void {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'Укажите причину отмены.' );
		}

		$peek          = $this->registrations->find( $registrationId );
		$participation = null !== $peek ? $this->participations->find( $peek->participationId ) : null;
		$this->assertStaffScope( $actorUserId, $participation );

		$this->inTransactionWithRetry( function () use ( $actorUserId, $registrationId, $reason, $participation ): void {
			$locked       = $this->participations->findForUpdate( (int) $participation?->id );
			$registration = $this->registrations->find( $registrationId );
			if ( null === $locked || null === $registration || 1 !== $registration->activeSlot ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
			}
			if ( null !== $locked->currentAttemptId ) {
				throw new CodedException( ErrorCode::ExamStarted, 'Попытка уже начата: запись отменить нельзя.' );
			}

			$sessions = $this->sessions->lockInOrder( array( $registration->sessionId ) );
			$session  = $sessions[ $registration->sessionId ] ?? null;
			if ( null === $session ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
			}

			$this->closeRegistration( $locked, $registration, $session, ExamRegistrationStatus::Cancelled, $this->time->nowUtc(), $reason, $actorUserId, 'staff' );
		} );
	}

	/**
	 * Перенос сотрудником с причиной: окно записи и «до начала своего сеанса» не проверяются, попытки быть не должно.
	 *
	 * @throws CodedException
	 */
	public function transferByStaff( int $actorUserId, int $registrationId, int $newSessionId, string $reason ): RegistrationResultDTO {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'Укажите причину переноса.' );
		}

		$peek          = $this->registrations->find( $registrationId );
		$participation = null !== $peek ? $this->participations->find( $peek->participationId ) : null;
		$this->assertStaffScope( $actorUserId, $participation );

		return $this->inTransactionWithRetry( function () use ( $actorUserId, $registrationId, $newSessionId, $reason, $participation ): RegistrationResultDTO {
			$this->lockParticipant( (int) $participation?->participantId );
			$locked  = $this->participations->findForUpdate( (int) $participation?->id );
			$current = $this->registrations->find( $registrationId );
			if ( null === $locked || null === $current || 1 !== $current->activeSlot ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
			}

			return $this->moveRegistration( $locked, $current, $newSessionId, true, $actorUserId, $reason );
		} );
	}

	/**
	 * Все записи участия по возрастанию ID: ни одна операция не стирает строки.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function history( int $participationId ): array {
		return array_map(
			static fn ( ExamRegistrationDTO $r ): array => array(
				'registration_id' => $r->id,
				'session_id'      => $r->sessionId,
				'status'          => $r->status,
				'reason'          => $r->reason,
				'actor_user_id'   => $r->actorUserId,
				'created_at'      => $r->createdAt,
				'cancelled_at'    => $r->cancelledAt,
				'transferred_at'  => $r->transferredAt,
				'missed_at'       => $r->missedAt,
			),
			$this->registrations->findByParticipation( $participationId )
		);
	}

	/**
	 * Общий перенос: блокировка сеансов по возрастанию ID → проверки (в том числе пересечение с записью на другое
	 * проведение) → место в новом → закрыть старую → новая запись. Участник и участие уже заблокированы вызывающим.
	 * Любой отказ откатывает транзакцию, поэтому старая запись остаётся действующей.
	 */
	private function moveRegistration( ExamParticipationDTO $participation, ExamRegistrationDTO $current, int $newSessionId, bool $byStaff, ?int $actorUserId, ?string $reason ): RegistrationResultDTO {
		if ( $current->sessionId === $newSessionId ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Вы уже записаны на этот сеанс.' );
		}
		if ( null !== $participation->currentAttemptId ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Экзамен уже начат или сдан.' );
		}

		$locked     = $this->sessions->lockInOrder( array( $current->sessionId, $newSessionId ) );
		$oldSession = $locked[ $current->sessionId ] ?? null;
		$newSession = $locked[ $newSessionId ] ?? null;
		if ( null === $oldSession || null === $newSession || $oldSession->eventId !== $newSession->eventId ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Перенос возможен только между сеансами одного экзамена.' );
		}

		$event = $this->events->find( $newSession->eventId );
		if ( null === $event || ExamEventStatus::Published->value !== $event->status ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись на этот экзамен закрыта.' );
		}

		$nowUtc = $this->time->nowUtc();
		if ( ExamSessionStatus::Open->value !== $newSession->status ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс отменён.' );
		}
		if ( $byStaff ) {
			if ( $nowUtc >= $newSession->plannedEndAt ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс завершён.' );
			}
		} else {
			if ( $nowUtc >= $oldSession->scheduledAt ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже начался: перенос возможен только через преподавателя.' );
			}
			$this->assertRegistrationWindow( $event, $newSession, $nowUtc );
		}

		$this->assertNoOverlap( $participation->participantId, $newSession );

		if ( ! $this->sessions->occupySeat( $newSession->id ) ) {
			throw $this->noSeatsError( $newSession->id );
		}

		$this->sessions->releaseSeat( $oldSession->id );
		if ( ! $this->registrations->deactivate( $current->id, ExamRegistrationStatus::Transferred, $nowUtc, $reason, $actorUserId ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
		}

		$registrationId = $this->insertConfirmed( $participation->id, $newSession->id, null, $actorUserId, $nowUtc );
		$this->participations->setActiveRegistration( $participation->id, $registrationId );

		$payload = array(
			'old_session_id' => $oldSession->id,
			'new_session_id' => $newSession->id,
			'by'             => $byStaff ? 'staff' : 'self',
		);
		if ( null !== $reason ) {
			$payload['reason'] = $reason;
		}
		$this->outbox->add( ExamOutboxEvent::RegistrationTransferred, 'registration', $registrationId, 1, $payload );

		return new RegistrationResultDTO(
			registrationId : $registrationId,
			participationId: $participation->id,
			sessionId      : $newSession->id,
			status         : ExamRegistrationStatus::Confirmed,
			freeSeats      : $this->freeSeats( $newSession->id ),
		);
	}

	/** Закрывает действующую запись, освобождает место и пишет событие. */
	private function closeRegistration( ExamParticipationDTO $participation, ExamRegistrationDTO $registration, ExamSessionDTO $session, ExamRegistrationStatus $status, string $nowUtc, ?string $reason, ?int $actorUserId, string $by ): void {
		if ( ! $this->registrations->deactivate( $registration->id, $status, $nowUtc, $reason, $actorUserId ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Действующей записи нет.' );
		}

		$this->sessions->releaseSeat( $session->id );
		$this->participations->setActiveRegistration( $participation->id, null );

		$payload = array(
			'session_id' => $session->id,
			'by'         => $by,
		);
		if ( null !== $reason ) {
			$payload['reason'] = $reason;
		}
		$this->outbox->add( ExamOutboxEvent::RegistrationCancelled, 'registration', $registration->id, 1, $payload );
	}

	/**
	 * Правила записи: один порядок и одни коды для всех путей.
	 *
	 * @throws CodedException
	 */
	private function assertCanRegister( ExamEventDTO $event, ExamSessionDTO $session, ExamParticipationDTO $participation, string $nowUtc, bool $byStaff, bool $checkWindow = true ): void {
		if ( ExamEventStatus::Published->value !== $event->status ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись на этот экзамен закрыта.' );
		}
		if ( ExamSessionStatus::Open->value !== $session->status ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс отменён.' );
		}

		if ( $byStaff ) {
			if ( $nowUtc >= $session->plannedEndAt ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс завершён.' );
			}
		} elseif ( $checkWindow ) {
			$this->assertRegistrationWindow( $event, $session, $nowUtc );
		}

		if ( null !== $participation->currentAttemptId ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Экзамен уже начат или сдан.' );
		}
		if ( null !== $this->registrations->findActive( $participation->id ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Запись на этот экзамен уже есть.' );
		}
		$this->assertNoOverlap( $participation->participantId, $session );
	}

	/**
	 * «Мест нет»: различает полный сеанс и сеанс, где часть мест лишь удерживается бронью гостя, — ученику есть смысл попробовать позже.
	 */
	private function noSeatsError( int $sessionId ): CodedException {
		if ( $this->guestApplications->countHeldBySession( $sessionId ) > 0 ) {
			return new CodedException( ErrorCode::ExamHeld, 'Свободных мест сейчас нет: часть мест удерживается до оплаты. Попробуйте позже.' );
		}

		return new CodedException( ErrorCode::ExamFull, 'Свободных мест нет.' );
	}

	/**
	 * Сеанс пересекается с занятием ученика — предупреждение, а не отказ. Занятия — местное время, сеанс — UTC,
	 * поэтому окно сеанса переводится через {@see ExamTime}. Отменённые и перенесённые занятия слот не занимают.
	 */
	private function lessonOverlapWarning( int $personId, ExamSessionDTO $session ): bool {
		$start = $this->time->toLocal( $session->scheduledAt );
		$end   = $this->time->toLocal( $session->plannedEndAt );
		$days  = array_unique( array( substr( $start, 0, 10 ), substr( $end, 0, 10 ) ) );

		foreach ( $this->audience->groupIdsForStudent( $personId ) as $groupId ) {
			foreach ( $days as $day ) {
				foreach ( $this->lessons->listByGroupAndDay( $groupId, $day ) as $lesson ) {
					if ( null === $lesson->scheduledAt || in_array( $lesson->status, array( 'cancelled', 'moved' ), true ) ) {
						continue;
					}

					$lessonEnd = $lesson->endsAt ?? $this->time->addMinutes( $lesson->scheduledAt, self::DEFAULT_LESSON_MINUTES );
					if ( $lesson->scheduledAt < $end && $lessonEnd > $start ) {
						return true;
					}
				}
			}
		}

		return false;
	}
	/**
	 * Одно правило для записи и переноса: в это время у участника нет действующей записи на другое проведение.
	 * Надёжно только под блокировкой участника ({@see lockParticipant()}).
	 *
	 * @throws CodedException
	 */
	private function assertNoOverlap( int $participantId, ExamSessionDTO $session ): void {
		if ( $this->registrations->hasOverlappingActive( $participantId, $session->scheduledAt, $session->plannedEndAt, $session->eventId ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'В это время уже есть запись на другой экзамен.' );
		}
	}

	/** @throws CodedException */
	private function assertRegistrationWindow( ExamEventDTO $event, ExamSessionDTO $session, string $nowUtc ): void {
		if ( null !== $event->registrationOpensAt && $nowUtc < $event->registrationOpensAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись ещё не открыта.' );
		}
		if ( null !== $event->registrationClosesAt && $nowUtc >= $event->registrationClosesAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись закрыта.' );
		}
		if ( $nowUtc >= $session->scheduledAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже начался.' );
		}
	}

	/**
	 * Блокирует участника, затем его участие в проведении. Вызывать первым оператором транзакции:
	 * идентификатор участника определяют до неё.
	 */
	private function lockParticipation( int $participantId, int $eventId ): ?ExamParticipationDTO {
		$this->lockParticipant( $participantId );
		$participation = $this->participations->findByEventAndParticipant( $eventId, $participantId );
		return null !== $participation ? $this->participations->findForUpdate( $participation->id ) : null;
	}

	/**
	 * Блокирует строку участника. Вызывать до блокировки участия и сеансов.
	 *
	 * @throws CodedException Участника нет.
	 */
	private function lockParticipant( int $participantId ): void {
		if ( null === $this->participants->findForUpdate( $participantId ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
		}
	}

	/**
	 * Вкладка видит устаревшее состояние участия — действовать по нему нельзя.
	 *
	 * @throws CodedException
	 */
	private function assertFreshVersion( ExamParticipationDTO $participation, ?int $expectedVersion ): void {
		if ( null !== $expectedVersion && $participation->version !== $expectedVersion ) {
			throw new CodedException( ErrorCode::ExamStale, 'Запись изменилась в другой вкладке. Обновите страницу.' );
		}
	}

	/** Сотрудник вправе управлять предметом проведения, иначе — тот же отказ, что и для несуществующей записи. */
	private function assertStaffScope( int $actorUserId, ?ExamParticipationDTO $participation ): void {
		$event = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $event || ! $this->accessGuard->canManageSubject( $actorUserId, $event->subjectKey ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Запись не найдена.' );
		}
	}

	private function insertConfirmed( int $participationId, int $sessionId, ?string $requestKey, ?int $actorUserId, string $nowUtc ): int {
		$row = array(
			'participation_id' => $participationId,
			'session_id'       => $sessionId,
			'status'           => ExamRegistrationStatus::Confirmed->value,
			'active_slot'      => 1,
			'created_at'       => $nowUtc,
		);
		if ( null !== $requestKey ) {
			$row['request_key'] = $requestKey;
		}
		if ( null !== $actorUserId ) {
			$row['actor_user_id'] = $actorUserId;
		}

		try {
			return $this->registrations->insert( $row );
		} catch ( DuplicateKeyException ) {
			// Вторая линия защиты: уникальный индекс (participation_id, active_slot).
			throw new CodedException( ErrorCode::ExamConflict, 'Запись на этот экзамен уже есть.' );
		}
	}

	private function freeSeats( int $sessionId ): int {
		$session = $this->sessions->find( $sessionId );
		return null !== $session ? max( 0, $session->capacity - $session->occupiedCount ) : 0;
	}

	/** @throws CodedException */
	private function assertRequestKey( string $requestKey ): void {
		if ( '' === $requestKey || strlen( $requestKey ) > self::REQUEST_KEY_MAX_LENGTH ) {
			throw new CodedException( ErrorCode::ExamReplay, 'Повторите действие.' );
		}
	}

	private function scope( int $participantId ): string {
		return 'participant:' . $participantId;
	}

	/**
	 * Действующий ключ операции или null. Тот же ключ с другими данными — `ExamReplay`.
	 *
	 * @throws CodedException
	 */
	private function findOperation( int $participantId, string $operation, string $requestKey, string $payload ): ?ExamOperationKeyDTO {
		$row = $this->operationKeys->findByKey( $this->scope( $participantId ), $operation, $requestKey );
		if ( null === $row || $row->expiresAt <= $this->time->nowUtc() ) {
			return null;
		}
		if ( ! hash_equals( $row->payloadHash, hash( 'sha256', $payload ) ) ) {
			throw new CodedException( ErrorCode::ExamReplay, 'Повторите действие.' );
		}
		return $row;
	}

	private function rememberOperation( int $participantId, string $operation, string $requestKey, string $payload, string $resultRef ): void {
		$now = $this->time->nowUtc();
		$this->operationKeys->remember(
			$this->scope( $participantId ),
			$operation,
			$requestKey,
			hash( 'sha256', $payload ),
			$resultRef,
			$this->time->addMinutes( $now, self::OPERATION_TTL_MINUTES ),
			$now
		);
	}
}
