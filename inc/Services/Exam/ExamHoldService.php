<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Shared\CodedException;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Временная бронь места гостя до оплаты (SPEC §6). Форма, корзина и оплата — этап 11a; здесь только сервис.
 *
 * **Одна вместимость на всех.** Бронь занимает место в `exam_sessions.occupied_count` тем же условным `UPDATE`, что и запись ученика,
 * поэтому ученик и гость на последнее место — один победитель. Конвертация брони в запись второго места не занимает.
 *
 * **«Освобождается ровно один раз».** Место возвращает только тот вызов, который снял флаг `is_held` условным запросом
 * ({@see ExamGuestApplicationRepository::releaseHeldFlag()}); остальные получают `false` и ничего не освобождают.
 *
 * **Порядок блокировок.** Оформление: сеанс → просроченные заявки этого сеанса. Подтверждение и освобождение: заявка → (участник → участие →) сеанс.
 * Две цепочки пересекаются на заявках, и взаимная блокировка возможна; тело каждой транзакции безопасно для повтора,
 * поэтому все операции идут через `inTransactionWithRetry()`.
 *
 * Данные заявки при освобождении **не удаляются**: поздняя оплата должна её найти.
 */
class ExamHoldService {

	use TransactionRunner;

	public function __construct(
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamEventRepository $events,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamRegistrationService $registrations,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
		private readonly GuestParticipantMaterializer $materializer,
	) {}

	/**
	 * Оформляет заявку и держит за ней место `$ttlMinutes` минут. Повтор с тем же ключом источника возвращает прежнюю заявку:
	 * место второй раз не занимается, бронь не продлевается.
	 *
	 * @param array<string, mixed> $data `event_id`, `session_id`, `source_id`, `identity_hash`, `request_key`, `draft_enc`, `source_snapshot`,
	 *                                   `consent_refs`, `ip_hash`, `created_by_user_id` (заявка сотрудника на месте).
	 *
	 * @throws CodedException
	 */
	public function capture( array $data, int $ttlMinutes ): ExamGuestApplicationDTO {
		$eventId      = (int) ( $data['event_id'] ?? 0 );
		$sessionId    = (int) ( $data['session_id'] ?? 0 );
		$sourceId     = (int) ( $data['source_id'] ?? 0 );
		$identityHash = (string) ( $data['identity_hash'] ?? '' );
		$requestKey   = (string) ( $data['request_key'] ?? '' );
		$staffUserId  = isset( $data['created_by_user_id'] ) ? (int) $data['created_by_user_id'] : null;

		if ( $eventId <= 0 || $sessionId <= 0 || $sourceId <= 0 || '' === $identityHash || '' === $requestKey || strlen( $requestKey ) > 64 || $ttlMinutes < 1 ) {
			throw new CodedException( ErrorCode::ExamReplay, 'Повторите действие.' );
		}

		return $this->inTransactionWithRetry( function () use ( $data, $eventId, $sessionId, $sourceId, $identityHash, $requestKey, $staffUserId, $ttlMinutes ): ExamGuestApplicationDTO {
			// Первый оператор транзакции — блокировка сеанса: ею сериализуются заявки, ученики и освобождение на этот сеанс.
			$session = $this->sessions->findForUpdate( $sessionId );
			if ( null === $session || $session->eventId !== $eventId ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс не найден.' );
			}

			$existing = $this->applications->findBySourceAndRequestKey( $sourceId, $requestKey );
			if ( null !== $existing ) {
				if ( $existing->sessionId !== $sessionId || ! hash_equals( $existing->identityHash, $identityHash ) ) {
					throw new CodedException( ErrorCode::ExamReplay, 'Повторите действие.' );
				}
				return $existing;
			}

			$event = $this->events->find( $eventId );
			if ( null === $event ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс не найден.' );
			}

			$nowUtc = $this->time->nowUtc();

			// Места, занятые истёкшими бронями этого сеанса, освобождаются здесь же — не дожидаясь минутного тика.
			foreach ( $this->applications->listExpiredHeldIdsBySession( $sessionId, $nowUtc ) as $expiredId ) {
				$expired = $this->applications->findForUpdate( $expiredId );
				if ( null !== $expired ) {
					$this->releaseLocked( $expired, GuestApplicationState::ExpiredUnpaid );
				}
			}

			$this->assertCanCapture( $event->status, $event->guestRegistrationEnabled, $event->registrationOpensAt, $event->registrationClosesAt, $session->status, $session->scheduledAt, $session->plannedEndAt, null !== $staffUserId, $nowUtc );

			if ( ! $this->sessions->occupySeat( $sessionId ) ) {
				throw new CodedException( ErrorCode::ExamFull, 'Свободных мест нет.' );
			}

			$limits = array( $this->time->addMinutes( $nowUtc, $ttlMinutes ) );
			if ( null === $staffUserId ) {
				if ( null !== $event->registrationClosesAt ) {
					$limits[] = $event->registrationClosesAt;
				}
				$limits[] = $session->scheduledAt;
			} else {
				$limits[] = $session->plannedEndAt;
			}

			$row = array(
				'event_id'        => $eventId,
				'session_id'      => $sessionId,
				'source_id'       => $sourceId,
				'identity_hash'   => $identityHash,
				'active_slot'     => 1,
				'state'           => GuestApplicationState::Hold->value,
				'is_held'         => 1,
				'hold_expires_at' => min( $limits ),
				'request_key'     => $requestKey,
				'version'         => 1,
				'created_at'      => $nowUtc,
				'updated_at'      => $nowUtc,
			);
			foreach ( array( 'draft_enc', 'source_snapshot', 'consent_refs', 'ip_hash' ) as $column ) {
				if ( isset( $data[ $column ] ) && '' !== $data[ $column ] ) {
					$row[ $column ] = (string) $data[ $column ];
				}
			}
			if ( null !== $staffUserId ) {
				$row['created_by_user_id'] = $staffUserId;
			}

			try {
				$id = $this->applications->insert( $row );
			} catch ( DuplicateKeyException $e ) {
				// Текст ответа фиксирован: данные чужой заявки в нём быть не должны.
				if ( str_contains( $e->getMessage(), 'source_request' ) ) {
					throw new CodedException( ErrorCode::ExamReplay, 'Повторите действие.' );
				}
				throw new CodedException( ErrorCode::ExamConflict, 'Заявка на этот экзамен уже оформлена.' );
			}

			return $this->reload( $id );
		} );
	}

	/**
	 * Подтверждение после оплаты: превращает бронь (или, если она истекла, свободное место) в запись участника.
	 *
	 * Ветки по состоянию заявки под её блокировкой:
	 * - подтверждена, отменена, неявка, уже «нужна помощь» — вернуть как есть (повтор безвреден, воскрешения нет);
	 * - бронь действует — место уже занято: участник и запись создаются без `occupySeat()`;
	 * - бронь истекла — место занимается заново по обычным правилам записи; не удалось — `paid_needs_resolution`
	 *   и событие `PaidNeedsResolution`: место и другой сеанс сами не выбираются.
	 *
	 * @throws CodedException
	 */
	public function convert( int $applicationId, ?int $actorUserId ): ExamGuestApplicationDTO {
		return $this->inTransactionWithRetry( function () use ( $applicationId, $actorUserId ): ExamGuestApplicationDTO {
			$application = $this->applications->findForUpdate( $applicationId );
			if ( null === $application ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Заявка не найдена.' );
			}

			$state = GuestApplicationState::tryFrom( $application->state );
			if ( in_array( $state, array( GuestApplicationState::Confirmed, GuestApplicationState::Cancelled, GuestApplicationState::Missed, GuestApplicationState::PaidNeedsResolution ), true ) ) {
				return $application;
			}

			$participantId = $application->participantId ?? $this->createParticipant( $application );
			$requestKey    = 'app-' . $application->id;
			$sourceId      = $application->sourceId;

			if ( $application->isHeld ) {
				try {
					$result = $this->registrations->confirmHeld( $participantId, $application->sessionId, $requestKey, $sourceId, $actorUserId );

					return $this->finalize( $application, $participantId, $result->participationId, $result->registrationId );
				} catch ( CodedException ) {
					// Сеанс отменён или участие уже занято другой записью: бронь снимается, оплаченную заявку разбирает сотрудник.
					$this->giveUpSeat( $application );

					return $this->markNeedsResolution( $application, $participantId );
				}
			}

			// Бронь уже освобождена: личность могла оформить новую заявку, и вернуть активный слот этой нельзя.
			$slotFree = ! $this->applications->hasActiveByIdentity( $application->eventId, $application->identityHash, $application->id );
			if ( $slotFree ) {
				try {
					$result = $this->registrations->confirmLate( $participantId, $application->sessionId, $requestKey, $sourceId, $actorUserId );

					return $this->finalize( $application, $participantId, $result->participationId, $result->registrationId );
				} catch ( CodedException ) {
					// Мест нет, сеанс начался или отменён: оплата получена, место не занято — разбирает сотрудник.
				}
			}

			return $this->markNeedsResolution( $application, $participantId );
		} );
	}

	/**
	 * Освобождает бронь одной заявки: компенсация сбоя корзины, удаление позиции, отмена сотрудником.
	 *
	 * @return bool true — место освобождено этим вызовом; false — бронь уже снята.
	 *
	 * @throws \InvalidArgumentException Новое состояние обязано быть таким, где место не удерживается.
	 */
	public function release( int $applicationId, GuestApplicationState $newState ): bool {
		if ( $newState->holdsSeat() ) {
			throw new \InvalidArgumentException( 'Состояние после освобождения не должно удерживать место.' );
		}

		return (bool) $this->inTransactionWithRetry( function () use ( $applicationId, $newState ): bool {
			$application = $this->applications->findForUpdate( $applicationId );
			if ( null === $application || null === $this->sessions->findForUpdate( $application->sessionId ) ) {
				return false;
			}

			return $this->releaseLocked( $application, $newState );
		} );
	}

	/**
	 * Продвигает заявку по пути оплаты (`hold` → `awaiting_payment` → `payment_pending`) без изменения брони: место и срок прежние.
	 * Только вперёд и только из перечисленных состояний — повторный вызов и опоздавший хук ничего не откатывают.
	 *
	 * @param list<GuestApplicationState> $from
	 *
	 * @return bool true — состояние сменено этим вызовом.
	 */
	public function transition( int $applicationId, array $from, GuestApplicationState $to ): bool {
		return (bool) $this->inTransactionWithRetry( function () use ( $applicationId, $from, $to ): bool {
			$application = $this->applications->findForUpdate( $applicationId );
			$state       = null !== $application ? GuestApplicationState::tryFrom( $application->state ) : null;
			if ( null === $application || null === $state || ! in_array( $state, $from, true ) ) {
				return false;
			}

			return $this->applications->update( $application->id, array( 'state' => $to->value ), $application->version );
		} );
	}

	/**
	 * Для минутного тика: освобождает просроченные брони, каждую в своей транзакции. Ошибка одной заявки логируется
	 * и не останавливает остальные.
	 *
	 * @return int Сколько броней освобождено.
	 */
	public function releaseExpired( int $limit = 100 ): int {
		$released = 0;

		foreach ( $this->applications->listExpiredHeldIds( $this->time->nowUtc(), $limit ) as $applicationId ) {
			try {
				$done = $this->inTransactionWithRetry( function () use ( $applicationId ): bool {
					$application = $this->applications->findForUpdate( $applicationId );
					if ( null === $application || $this->sessions->findForUpdate( $application->sessionId ) === null ) {
						return false;
					}

					// Повторная проверка под блокировками: бронь могли подтвердить или снять, пока тик ждал.
					if ( ! $application->isHeld || null === $application->holdExpiresAt || $application->holdExpiresAt > $this->time->nowUtc() ) {
						return false;
					}

					return $this->releaseLocked( $application, GuestApplicationState::ExpiredUnpaid );
				} );

				if ( $done ) {
					++$released;
				}
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamHold', $e, array( 'application_id' => $applicationId ), true );
			}
		}

		return $released;
	}

	/**
	 * Снимает бронь под блокировкой сеанса и заявки. Флаг переключается один раз: только тот, кто его снял, возвращает место.
	 */
	private function releaseLocked( ExamGuestApplicationDTO $application, GuestApplicationState $newState ): bool {
		if ( ! $this->applications->releaseHeldFlag( $application->id ) ) {
			return false;
		}

		$this->sessions->releaseSeat( $application->sessionId );
		$this->applications->update( $application->id, array( 'state' => $newState->value, 'active_slot' => null ), $application->version );

		return true;
	}

	/** Заявка стала записью: участник, участие, запись и (если место удерживалось) снятый флаг брони. */
	private function finalize( ExamGuestApplicationDTO $application, int $participantId, int $participationId, int $registrationId ): ExamGuestApplicationDTO {
		$updated = $this->applications->update( $application->id, array(
			'state'            => GuestApplicationState::Confirmed->value,
			'is_held'          => 0,
			'active_slot'      => 1,
			'hold_expires_at'  => null,
			'participant_id'   => $participantId,
			'participation_id' => $participationId,
			'registration_id'  => $registrationId,
		), $application->version );
		if ( ! $updated ) {
			throw new \RuntimeException( 'Заявка изменилась во время подтверждения.' );
		}

		return $this->reload( $application->id );
	}

	/** Снимает бронь, если она ещё держит место (флаг переключается один раз), и возвращает место сеансу. */
	private function giveUpSeat( ExamGuestApplicationDTO $application ): void {
		if ( $this->applications->releaseHeldFlag( $application->id ) ) {
			$this->sessions->releaseSeat( $application->sessionId );
		}
	}

	/** Оплата получена, а места нет: заявка помечается для разбора сотрудником, в outbox — событие. */
	private function markNeedsResolution( ExamGuestApplicationDTO $application, int $participantId ): ExamGuestApplicationDTO {
		$updated = $this->applications->update( $application->id, array(
			'state'          => GuestApplicationState::PaidNeedsResolution->value,
			'participant_id' => $participantId,
			'is_held'        => 0,
		), $application->version );
		if ( ! $updated ) {
			throw new \RuntimeException( 'Заявка изменилась во время подтверждения.' );
		}

		$this->outbox->add(
			ExamOutboxEvent::PaidNeedsResolution,
			'guest_application',
			$application->id,
			$application->version + 1,
			array( 'application_id' => $application->id, 'event_id' => $application->eventId, 'session_id' => $application->sessionId )
		);

		return $this->reload( $application->id );
	}

	/** Участник гостевой заявки: ФИО, телефон и мессенджер — из зашифрованного черновика, школа и класс — из снимка источника. */
	private function createParticipant( ExamGuestApplicationDTO $application ): int {
		return $this->materializer->materialize( $application );
	}

	/**
	 * Правила оформления заявки. Для заявки сотрудника на месте (`$byStaff`) окно записи не проверяется, но сеанс не должен закончиться.
	 *
	 * @throws CodedException
	 */
	private function assertCanCapture( string $eventStatus, bool $guestEnabled, ?string $opensAt, ?string $closesAt, string $sessionStatus, string $scheduledAt, string $plannedEndAt, bool $byStaff, string $nowUtc ): void {
		if ( ExamEventStatus::Published->value !== $eventStatus ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись на этот экзамен закрыта.' );
		}
		if ( ! $guestEnabled ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись для гостей закрыта.' );
		}
		if ( ExamSessionStatus::Open->value !== $sessionStatus ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс отменён.' );
		}

		if ( $byStaff ) {
			if ( $nowUtc >= $plannedEndAt ) {
				throw new CodedException( ErrorCode::ExamClosed, 'Сеанс завершён.' );
			}
			return;
		}

		if ( null !== $opensAt && $nowUtc < $opensAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись ещё не открыта.' );
		}
		if ( null !== $closesAt && $nowUtc >= $closesAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Запись закрыта.' );
		}
		if ( $nowUtc >= $scheduledAt ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже начался.' );
		}
	}

	private function reload( int $applicationId ): ExamGuestApplicationDTO {
		$application = $this->applications->find( $applicationId );
		if ( null === $application ) {
			throw new \RuntimeException( 'Заявка не найдена после сохранения.' );
		}

		return $application;
	}
}
