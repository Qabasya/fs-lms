<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamEventStatus;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Проведения экзаменов и их сеансы: черновик, правка, публикация со снимком варианта, отмена (SPEC §3, §12).
 *
 * **Права.** Каждый публичный метод первой строкой проверяет право: `canManageSubject` для создания, `canManageEvent` для остального.
 * Отказ — `CodedException( ExamAccess )`.
 *
 * **Транзакции и блокировки.** Тело каждой операции — одна транзакция с повтором при взаимной блокировке, поэтому оно не имеет
 * внешних побочных эффектов. Первый оператор — блокирующее чтение проведения; всё, что нужно для его выбора, читается до `START TRANSACTION`
 * (раннее чтение — только для отказа по праву). Порядок блокировок: проведение → кабинет → сеанс(ы) по возрастанию ID.
 * Регистрации идут другим путём (участие → сеансы) и проведение не блокируют, поэтому цикла нет.
 *
 * **Время.** Дата, время и «запись открыта/закрыта» приходят местными, хранятся в UTC; перевод — только через {@see ExamTime}.
 *
 * **Версии.** Правка принимает `expectedVersion` из формы; расхождение — `ExamStale`.
 */
class ExamEventService {

	use TransactionRunner;

	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamRegistrationRepository $registrations,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamAccessGuard $guard,
		private readonly ExamVariantPolicy $variants,
		private readonly ExamRoomService $rooms,
		private readonly ExamFormatRegistry $formats,
		private readonly AssessmentManager $assessments,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
		private readonly ExamRegistrationService $registrationService,
		private readonly ExamHoldService $holds,
		private readonly ExamGuestApplicationRepository $guestApplications,
		private readonly ExamLaunchChecklist $checklist,
	) {}

	/**
	 * Создаёт черновик проведения; владелец — вызывающий.
	 *
	 * @param array<string, mixed> $input `subject_key`, `title`, `description`, `period_from`, `period_to`, `registration_opens_at`,
	 *                                    `registration_closes_at`, `default_assessment_id`, `guest_registration_enabled`.
	 *
	 * @throws CodedException
	 */
	public function createDraft( int $actorUserId, array $input ): ExamEventDTO {
		$subjectKey = trim( (string) ( $input['subject_key'] ?? '' ) );
		if ( '' === $subjectKey || ! $this->guard->canManageSubject( $actorUserId, $subjectKey ) ) {
			throw $this->noAccess();
		}

		$fields = $this->eventFields( $input, $subjectKey );
		$now    = $this->time->nowUtc();

		$id = $this->events->insert( array_merge( $fields, array(
			'subject_key'   => $subjectKey,
			'owner_user_id' => $actorUserId,
			'status'        => ExamEventStatus::Draft->value,
			'version'       => 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		) ) );

		return $this->reload( $id );
	}

	/**
	 * Правит проведение: те же проверки, что при создании; предмет не меняется.
	 *
	 * @param array<string, mixed> $input Те же поля, что у {@see createDraft()}; `subject_key` игнорируется.
	 *
	 * @throws CodedException
	 */
	public function updateEvent( int $actorUserId, int $eventId, array $input, int $expectedVersion ): ExamEventDTO {
		$early = $this->requireEvent( $eventId );
		$this->assertCanManage( $actorUserId, $early );

		$fields = $this->eventFields( $input, $early->subjectKey );
		if ( 1 === (int) $fields['guest_registration_enabled'] ) {
			$this->assertGuestLaunchReady( $early );
		}

		return $this->inTransactionWithRetry( function () use ( $eventId, $fields, $expectedVersion ): ExamEventDTO {
			$event = $this->lockEvent( $eventId );
			$this->assertEditable( $event );
			$this->assertVersion( $event->version, $expectedVersion );

			foreach ( $this->sessions->findByEvent( $eventId ) as $session ) {
				if ( ExamSessionStatus::Cancelled->value === $session->status ) {
					continue;
				}
				$day = substr( $this->time->toLocal( $session->scheduledAt ), 0, 10 );
				if ( $day < $fields['period_from'] || $day > $fields['period_to'] ) {
					throw new CodedException( ErrorCode::ExamConflict, 'Есть сеансы вне нового периода проведения.' );
				}
			}

			if ( ! $this->events->update( $eventId, $fields, $expectedVersion ) ) {
				throw $this->stale();
			}

			return $this->reload( $eventId );
		} );
	}

	/**
	 * Создаёт или правит сеанс. Конец и вместимость во входе нет: конец — начало плюс длительность формата, вместимость — места кабинета.
	 *
	 * @param array<string, mixed> $input `date` (`Y-m-d`), `time` (`H:i`) — местные; `assessment_id`; `room_id`.
	 * @param ?int                 $sessionId       null — новый сеанс.
	 * @param ?int                 $expectedVersion Версия сеанса из формы (для правки обязательна).
	 *
	 * @throws CodedException
	 */
	public function saveSession( int $actorUserId, int $eventId, array $input, ?int $sessionId, ?int $expectedVersion ): ExamSessionDTO {
		$early = $this->requireEvent( $eventId );
		$this->assertCanManage( $actorUserId, $early );

		if ( null !== $sessionId ) {
			$current = $this->sessions->find( $sessionId );
			if ( null === $current || $current->eventId !== $eventId ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
			}
			if ( null === $expectedVersion ) {
				throw $this->stale();
			}
		}

		$date         = $this->dateOf( $input['date'] ?? '', 'Укажите дату сеанса.' );
		$time         = $this->timeOf( $input['time'] ?? '' );
		$assessmentId = (int) ( $input['assessment_id'] ?? 0 );
		$roomId       = (int) ( $input['room_id'] ?? 0 );

		if ( $date < $early->periodFrom || $date > $early->periodTo ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Дата сеанса вне периода проведения.' );
		}
		if ( $roomId <= 0 ) {
			throw new CodedException( ErrorCode::ExamRoom, 'Выберите кабинет.' );
		}

		$this->variants->assert( $assessmentId, $early->subjectKey );
		$assessment = $this->assessments->get( $assessmentId );
		$format     = null !== $assessment ? $this->formats->for( $assessment->kind ) : null;
		if ( null === $format ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Формат экзамена недоступен: модуль экзаменов выключен.' );
		}

		$startUtc = $this->time->toUtc( $date . ' ' . $time . ':00' );
		$endUtc   = $this->time->addMinutes( $startUtc, $format->durationMinutes );

		return $this->inTransactionWithRetry( function () use ( $eventId, $sessionId, $expectedVersion, $assessmentId, $roomId, $startUtc, $endUtc ): ExamSessionDTO {
			$event = $this->lockEvent( $eventId );
			$this->assertEditable( $event );

			$this->rooms->lock( $roomId );
			$room = $this->rooms->assertUsable( $roomId, $event->subjectKey );

			$current = null;
			if ( null !== $sessionId ) {
				$current = $this->sessions->findForUpdate( $sessionId );
				if ( null === $current || $current->eventId !== $eventId ) {
					throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
				}
				$this->assertVersion( $current->version, (int) $expectedVersion );
				$this->assertSessionEditable( $current, $assessmentId, $startUtc, $endUtc, $roomId, $room->seats );
			}

			$this->rooms->assertFree( $roomId, $startUtc, $endUtc, $current->id ?? 0 );

			$fields = array(
				'assessment_id'       => $assessmentId,
				'scheduled_at'        => $startUtc,
				'planned_end_at'      => $endUtc,
				'room_id'             => $roomId,
				'capacity'            => $room->seats,
				'responsible_user_id' => $event->ownerUserId,
			);

			if ( null === $current ) {
				$now = $this->time->nowUtc();
				$id  = $this->sessions->insert( array_merge( $fields, array(
					'event_id'       => $eventId,
					'occupied_count' => 0,
					'status'         => ExamSessionStatus::Open->value,
					'version'        => 1,
					'created_at'     => $now,
					'updated_at'     => $now,
				) ) );
			} else {
				$id = $current->id;
				if ( ! $this->sessions->update( $id, $fields, (int) $expectedVersion ) ) {
					throw $this->stale();
				}
			}

			$this->addVariantToPublishedSnapshot( $event, $assessmentId );

			$saved = $this->sessions->find( $id );
			if ( null === $saved ) {
				throw new \RuntimeException( 'Сеанс не найден после сохранения.' );
			}
			return $saved;
		} );
	}

	/**
	 * Удаляет сеанс без единой записи. Сеанс с записями отменяется (этап 8.3), а не удаляется.
	 *
	 * @throws CodedException
	 */
	public function deleteSession( int $actorUserId, int $sessionId ): void {
		$session = $this->sessions->find( $sessionId );
		if ( null === $session ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
		}
		$this->assertCanManage( $actorUserId, $this->requireEvent( $session->eventId ) );

		$this->inTransactionWithRetry( function () use ( $session ): void {
			$event = $this->lockEvent( $session->eventId );
			$this->assertEditable( $event );

			$locked = $this->sessions->findForUpdate( $session->id );
			if ( null === $locked ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
			}
			if ( $locked->occupiedCount > 0 || array() !== $this->registrations->listBySession( $locked->id ) ) {
				throw new CodedException( ErrorCode::ExamConflict, 'В сеансе есть записи: используйте отмену сеанса.' );
			}

			$this->sessions->delete( $locked->id );
		} );
	}

	/**
	 * Публикует черновик: проверяет сеансы и запись, фиксирует снимок каждого варианта, пишет событие в outbox — одной транзакцией.
	 *
	 * @throws CodedException
	 */
	public function publish( int $actorUserId, int $eventId, int $expectedVersion ): ExamEventDTO {
		$early = $this->requireEvent( $eventId );
		$this->assertCanManage( $actorUserId, $early );
		if ( $early->guestRegistrationEnabled ) {
			$this->assertGuestLaunchReady( $early );
		}

		return $this->inTransactionWithRetry( function () use ( $eventId, $expectedVersion ): ExamEventDTO {
			$event = $this->lockEvent( $eventId );
			$this->assertVersion( $event->version, $expectedVersion );

			if ( ExamEventStatus::Draft->value !== $event->status ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Опубликовать можно только черновик.' );
			}

			$now      = $this->time->nowUtc();
			$sessions = array_filter(
				$this->sessions->findByEvent( $eventId ),
				static fn ( ExamSessionDTO $s ): bool => ExamSessionStatus::Cancelled->value !== $s->status
			);

			$hasFutureOpen = false;
			foreach ( $sessions as $session ) {
				if ( ExamSessionStatus::Open->value === $session->status && $session->scheduledAt > $now ) {
					$hasFutureOpen = true;
					break;
				}
			}
			if ( ! $hasFutureOpen ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Добавьте хотя бы один сеанс в будущем.' );
			}
			if ( null === $event->registrationOpensAt || null === $event->registrationClosesAt ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Задайте время открытия и закрытия записи.' );
			}

			$snapshot = array();
			foreach ( array_unique( array_map( static fn ( ExamSessionDTO $s ): int => $s->assessmentId, $sessions ) ) as $assessmentId ) {
				$this->variants->assert( $assessmentId, $event->subjectKey );
				$snapshot[ (string) $assessmentId ] = $this->buildSnapshot( $assessmentId );
			}

			$updated = $this->events->update( $eventId, array(
				'status'           => ExamEventStatus::Published->value,
				'published_at'     => $now,
				'variant_snapshot' => (string) wp_json_encode( $snapshot ),
			), $event->version );
			if ( ! $updated ) {
				throw $this->stale();
			}

			$this->outbox->add( ExamOutboxEvent::EventPublished, 'event', $eventId, $event->version + 1, array( 'event_id' => $eventId ) );

			return $this->reload( $eventId );
		} );
	}

	/**
	 * Снимок варианта: небольшой конфиг, а не копия банка (SPEC §12). Состав и длительность фиксируются при публикации,
	 * чтобы правка работы после старта не меняла условия тем, кто уже её решает.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CodedException
	 */
	public function buildSnapshot( int $assessmentId ): array {
		$assessment = $this->assessments->get( $assessmentId );
		$format     = null !== $assessment ? $this->formats->for( $assessment->kind ) : null;
		if ( null === $assessment || null === $format ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Вариант не найден или формат экзамена недоступен.' );
		}

		$taskNumbers = array();
		$taskPoints  = array();
		foreach ( $assessment->taskIds as $taskId ) {
			if ( isset( $assessment->taskNumbers[ $taskId ] ) ) {
				$taskNumbers[ $taskId ] = $assessment->taskNumbers[ $taskId ];
			}
			if ( isset( $assessment->taskPoints[ $taskId ] ) ) {
				$taskPoints[ $taskId ] = $assessment->taskPoints[ $taskId ];
			}
		}

		return array(
			'assessment_id'    => $assessmentId,
			'kind'             => $assessment->kind->value,
			'task_ids'         => $assessment->taskIds,
			'task_numbers'     => $taskNumbers,
			'task_points'      => $taskPoints,
			'duration_minutes' => $format->durationMinutes,
			'primary_max'      => $format->primaryMax,
			'secondary_max'    => $format->secondaryMax,
			'scale'            => $format->scale,
			'built_at'         => $this->time->nowUtc(),
		);
	}

	/**
	 * Пересобирает снимок варианта после правки опечатки в работе. Пока сеанс с этим вариантом идёт или у него есть
	 * незавершённые попытки, снимок менять нельзя: он определяет длительность и состав идущих попыток.
	 *
	 * @throws CodedException
	 */
	public function rebuildSnapshot( int $actorUserId, int $eventId, int $assessmentId ): void {
		$this->assertCanManage( $actorUserId, $this->requireEvent( $eventId ) );

		$this->inTransactionWithRetry( function () use ( $eventId, $assessmentId ): void {
			$event = $this->lockEvent( $eventId );
			$this->assertEditable( $event );

			$now  = $this->time->nowUtc();
			$used = false;
			foreach ( $this->sessions->findByEvent( $eventId ) as $session ) {
				if ( $session->assessmentId !== $assessmentId || ExamSessionStatus::Cancelled->value === $session->status ) {
					continue;
				}
				$used = true;
				if ( $session->scheduledAt <= $now && $now < $session->plannedEndAt ) {
					throw new CodedException( ErrorCode::ExamConflict, 'Идёт сеанс с этим вариантом: снимок менять нельзя.' );
				}
			}
			if ( ! $used ) {
				throw new CodedException( ErrorCode::ExamConflict, 'В проведении нет сеансов с этим вариантом.' );
			}
			if ( $this->attempts->countInProgressExamByEvent( $eventId, $assessmentId ) > 0 ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Идёт сеанс с этим вариантом: снимок менять нельзя.' );
			}

			$snapshot                        = $this->snapshotOf( $event );
			$snapshot[ (string) $assessmentId ] = $this->buildSnapshot( $assessmentId );

			if ( ! $this->events->update( $eventId, array( 'variant_snapshot' => (string) wp_json_encode( $snapshot ) ), $event->version ) ) {
				throw $this->stale();
			}
		} );
	}

	/**
	 * Переносит сеанс **с участниками** на другие дату, время или кабинет: причина обязательна, записи остаются на сеансе.
	 *
	 * Вариант не меняется (участники записывались на конкретный). Сеанс без записей по-прежнему правится {@see saveSession()} без причины.
	 * Уведомления участников строит этап 9 из события `SessionMoved`.
	 *
	 * @param array<string, mixed> $input `date` (`Y-m-d`), `time` (`H:i`) — местные; `room_id`.
	 *
	 * @throws CodedException
	 */
	public function moveSession( int $actorUserId, int $sessionId, array $input, string $reason, int $expectedVersion ): ExamSessionDTO {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите причину переноса.' );
		}

		$peek  = $this->sessions->find( $sessionId );
		$early = null !== $peek ? $this->requireEvent( $peek->eventId ) : null;
		if ( null === $peek || null === $early ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
		}
		$this->assertCanManage( $actorUserId, $early );

		$date   = $this->dateOf( $input['date'] ?? '', 'Укажите дату сеанса.' );
		$time   = $this->timeOf( $input['time'] ?? '' );
		$roomId = (int) ( $input['room_id'] ?? 0 );
		if ( $date < $early->periodFrom || $date > $early->periodTo ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Дата сеанса вне периода проведения.' );
		}
		if ( $roomId <= 0 ) {
			throw new CodedException( ErrorCode::ExamRoom, 'Выберите кабинет.' );
		}

		$startUtc = $this->time->toUtc( $date . ' ' . $time . ':00' );

		return $this->inTransactionWithRetry( function () use ( $sessionId, $peek, $roomId, $startUtc, $reason, $expectedVersion ): ExamSessionDTO {
			$event = $this->lockEvent( $peek->eventId );
			$this->assertEditable( $event );

			$this->rooms->lock( $roomId );
			$room = $this->rooms->assertUsable( $roomId, $event->subjectKey );

			$current = $this->sessions->findForUpdate( $sessionId );
			if ( null === $current || $current->eventId !== $event->id ) {
				throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
			}
			$this->assertVersion( $current->version, $expectedVersion );
			$this->assertSessionMovable( $current, $room->seats );

			// Длительность сохраняется: конец переносится вместе с началом.
			$endUtc = $this->time->addMinutes( $startUtc, $this->durationMinutes( $current ) );
			$this->rooms->assertFree( $roomId, $startUtc, $endUtc, $current->id );

			$fields = array(
				'scheduled_at'   => $startUtc,
				'planned_end_at' => $endUtc,
				'room_id'        => $roomId,
				'capacity'       => $room->seats,
			);
			if ( ! $this->sessions->update( $current->id, $fields, $expectedVersion ) ) {
				throw $this->stale();
			}

			$this->outbox->add( ExamOutboxEvent::SessionMoved, 'session', $current->id, $expectedVersion + 1, array(
				'session_id'       => $current->id,
				'event_id'         => $event->id,
				'old_scheduled_at' => $current->scheduledAt,
				'new_scheduled_at' => $startUtc,
				'old_room_id'      => $current->roomId,
				'new_room_id'      => $roomId,
				'reason'           => $reason,
			) );

			$saved = $this->sessions->find( $current->id );
			if ( null === $saved ) {
				throw new \RuntimeException( 'Сеанс не найден после переноса.' );
			}
			return $saved;
		} );
	}

	/**
	 * Отменяет сеанс: причина обязательна, сеанс не должен быть начат. Каждая действующая запись отменяется с той же причиной
	 * (место освобождается, участник может записаться на другой сеанс), брони гостей снимаются.
	 *
	 * Сначала сеанс помечается отменённым — это закрывает запись и старт новых попыток; затем отменяются записи, каждая в своей
	 * транзакции. Если на этом шаге произошёл сбой, повторный вызов доотменяет оставшиеся записи (сеанс уже отменён).
	 *
	 * @throws CodedException
	 */
	public function cancelSession( int $actorUserId, int $sessionId, string $reason, int $expectedVersion ): void {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите причину отмены.' );
		}

		$peek  = $this->sessions->find( $sessionId );
		$early = null !== $peek ? $this->requireEvent( $peek->eventId ) : null;
		if ( null === $peek || null === $early ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
		}
		$this->assertCanManage( $actorUserId, $early );

		// Уже отменённый сеанс — только доводка записей, версия и статус не проверяются.
		if ( ExamSessionStatus::Cancelled->value !== $peek->status ) {
			$this->inTransactionWithRetry( function () use ( $sessionId, $peek, $reason, $expectedVersion ): void {
				$event = $this->lockEvent( $peek->eventId );
				$this->assertEditable( $event );

				$current = $this->sessions->findForUpdate( $sessionId );
				if ( null === $current || $current->eventId !== $event->id ) {
					throw new CodedException( ErrorCode::ExamConflict, 'Сеанс не найден.' );
				}
				$this->assertVersion( $current->version, $expectedVersion );
				if ( $current->isLocked() ) {
					throw new CodedException( ErrorCode::ExamStarted, 'Сеанс уже начат.' );
				}
				if ( ExamSessionStatus::Open->value !== $current->status ) {
					throw new CodedException( ErrorCode::ExamConflict, 'Сеанс завершён: отменить его нельзя.' );
				}

				if ( ! $this->sessions->update( $current->id, array( 'status' => ExamSessionStatus::Cancelled->value, 'cancel_reason' => $reason ), $expectedVersion ) ) {
					throw $this->stale();
				}
				$this->outbox->add( ExamOutboxEvent::SessionCancelled, 'session', $current->id, $expectedVersion + 1, array(
					'session_id' => $current->id,
					'event_id'   => $event->id,
					'reason'     => $reason,
				) );
			} );
		}

		$this->closeParticipantsOf( $actorUserId, $sessionId, $reason );
	}

	/**
	 * Отменяет проведение: причина обязательна; сеансы закрываются, записи отменяются, в outbox — `EventCancelled`.
	 * Проведение, где уже начата хотя бы одна попытка, отменить нельзя.
	 *
	 * @throws CodedException
	 */
	public function cancelEvent( int $actorUserId, int $eventId, string $reason, int $expectedVersion ): void {
		$this->assertCanManage( $actorUserId, $this->requireEvent( $eventId ) );

		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите причину отмены.' );
		}

		$sessionIds = $this->inTransactionWithRetry( function () use ( $eventId, $reason, $expectedVersion ): array {
			$event = $this->lockEvent( $eventId );
			$this->assertVersion( $event->version, $expectedVersion );
			$this->assertEditable( $event );

			$open = array();
			foreach ( $this->sessions->findByEvent( $eventId ) as $session ) {
				if ( $session->isLocked() ) {
					throw new CodedException( ErrorCode::ExamStarted, 'Экзамен уже начат: отмена невозможна.' );
				}
				if ( ExamSessionStatus::Cancelled->value !== $session->status ) {
					$open[] = $session->id;
				}
			}

			$now = $this->time->nowUtc();
			$this->sessions->cancelOpenByEvent( $eventId, $reason, $now );

			$updated = $this->events->update( $eventId, array(
				'status'        => ExamEventStatus::Cancelled->value,
				'cancel_reason' => $reason,
				'cancelled_at'  => $now,
			), $event->version );
			if ( ! $updated ) {
				throw $this->stale();
			}

			$this->outbox->add( ExamOutboxEvent::EventCancelled, 'event', $eventId, $event->version + 1, array( 'event_id' => $eventId, 'reason' => $reason ) );

			return $open;
		} );

		// Проведение и его сеансы уже закрыты: новые записи и старты невозможны, остаётся отменить действующие записи и брони.
		foreach ( $sessionIds as $sessionId ) {
			$this->closeParticipantsOf( $actorUserId, $sessionId, $reason );
		}
	}

	/**
	 * Завершает проведение, когда всё закончено: сеансы идут к концу или отменены, действующих записей без исхода нет, идущих попыток нет.
	 * Завершённое проведение остаётся доступным для проверки, утверждения и исправления результатов.
	 *
	 * Вызывается минутным тиком после неявок; прав не проверяет (системная операция).
	 *
	 * @return bool true — проведение завершено этим вызовом.
	 */
	public function completeIfDone( int $eventId ): bool {
		return (bool) $this->inTransactionWithRetry( function () use ( $eventId ): bool {
			$event = $this->events->findForUpdate( $eventId );
			if ( null === $event || ExamEventStatus::Published->value !== $event->status ) {
				return false;
			}

			$now      = $this->time->nowUtc();
			$sessions = array_filter(
				$this->sessions->findByEvent( $eventId ),
				static fn ( ExamSessionDTO $s ): bool => ExamSessionStatus::Cancelled->value !== $s->status
			);
			if ( array() === $sessions ) {
				return false;
			}
			foreach ( $sessions as $session ) {
				if ( $session->plannedEndAt > $now ) {
					return false;
				}
			}
			if ( $this->registrations->countActiveByEvent( $eventId ) > 0 || $this->attempts->countInProgressExamByEventAll( $eventId ) > 0 ) {
				return false;
			}

			$this->sessions->completeEndedByEvent( $eventId, $now );
			$updated = $this->events->update( $eventId, array(
				'status'       => ExamEventStatus::Completed->value,
				'completed_at' => $now,
			), $event->version );
			if ( ! $updated ) {
				throw $this->stale();
			}

			return true;
		} );
	}

	/**
	 * Проведения-кандидаты на завершение (все сеансы закончились) — для тика.
	 *
	 * @return int[]
	 */
	public function completionCandidates( int $limit = 100 ): array {
		return $this->events->listDueForCompletion( $this->time->nowUtc(), $limit );
	}

	/**
	 * Пересинхронизирует вместимость будущих открытых сеансов кабинета с его новым числом мест.
	 *
	 * Увеличение применяется всегда; уменьшение — только если ни в одном сеансе не занято больше мест, иначе отказ без изменений.
	 * `$persistRoom` выполняется в той же транзакции после синхронизации (сохранение самого кабинета): кабинет заблокирован,
	 * поэтому сеанс, назначаемый параллельно, возьмёт уже новое число мест.
	 *
	 * @param ?callable():void $persistRoom Сохранение кабинета; не вызывается, если синхронизация отказала.
	 *
	 * @throws CodedException
	 */
	public function syncCapacityForRoom( int $roomId, int $newSeats, ?callable $persistRoom = null ): void {
		$this->inTransactionWithRetry( function () use ( $roomId, $newSeats, $persistRoom ): void {
			$this->rooms->lock( $roomId );

			$future = $this->sessions->listFutureOpenByRoom( $roomId, $this->time->nowUtc() );
			$locked = $this->sessions->lockInOrder( array_map( static fn ( ExamSessionDTO $s ): int => $s->id, $future ) );

			foreach ( $locked as $session ) {
				if ( ExamSessionStatus::Open->value !== $session->status ) {
					continue;
				}
				$title = $this->events->find( $session->eventId )->title ?? '№' . $session->eventId;

				if ( $newSeats < 1 ) {
					throw new CodedException( ErrorCode::ExamConflict, sprintf( 'В кабинете запланирован сеанс проведения «%s»: вместимость не может быть нулевой.', $title ) );
				}
				if ( $newSeats < $session->occupiedCount ) {
					throw new CodedException(
						ErrorCode::ExamConflict,
						sprintf( 'Нельзя уменьшить вместимость до %d: в проведении «%s» уже занято %d мест.', $newSeats, $title, $session->occupiedCount )
					);
				}
			}

			foreach ( $locked as $session ) {
				if ( ExamSessionStatus::Open->value === $session->status && $session->capacity !== $newSeats ) {
					$this->sessions->setCapacity( $session->id, $newSeats );
				}
			}

			if ( null !== $persistRoom ) {
				$persistRoom();
			}
		} );
	}

	// ---------------------------------------------------------------------------------------------------------------------------------

	/**
	 * Поля проведения из формы — проверенные и в виде для записи (время записи в UTC).
	 *
	 * @param array<string, mixed> $input
	 *
	 * @return array{title: string, description: ?string, period_from: string, period_to: string, registration_opens_at: ?string,
	 *               registration_closes_at: ?string, default_assessment_id: ?int, guest_registration_enabled: int}
	 *
	 * @throws CodedException
	 */
	private function eventFields( array $input, string $subjectKey ): array {
		$title = trim( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите название проведения.' );
		}
		if ( mb_strlen( $title ) > 255 ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Название проведения не длиннее 255 символов.' );
		}

		$from = $this->dateOf( $input['period_from'] ?? '', 'Укажите даты проведения.' );
		$to   = $this->dateOf( $input['period_to'] ?? '', 'Укажите даты проведения.' );
		if ( $from > $to ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Дата начала позже даты окончания.' );
		}

		$opens  = $this->localDateTimeToUtc( $input['registration_opens_at'] ?? '' );
		$closes = $this->localDateTimeToUtc( $input['registration_closes_at'] ?? '' );
		if ( null !== $opens && null !== $closes && $opens > $closes ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Открытие записи позже её закрытия.' );
		}
		if ( null !== $closes && $closes > $this->time->endOfLocalDayUtc( $to ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Запись не может закрываться позже последнего дня проведения.' );
		}

		$defaultAssessment = (int) ( $input['default_assessment_id'] ?? 0 );
		if ( $defaultAssessment > 0 ) {
			$this->variants->assert( $defaultAssessment, $subjectKey );
		}

		$description = trim( (string) ( $input['description'] ?? '' ) );

		return array(
			'title'                      => $title,
			'description'                => '' === $description ? null : $description,
			'period_from'                => $from,
			'period_to'                  => $to,
			'registration_opens_at'      => $opens,
			'registration_closes_at'     => $closes,
			'default_assessment_id'      => $defaultAssessment > 0 ? $defaultAssessment : null,
			'guest_registration_enabled' => filter_var( $input['guest_registration_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0,
		);
	}

	/**
	 * Правка существующего сеанса: что можно менять.
	 *
	 * @throws CodedException
	 */
	private function assertSessionEditable( ExamSessionDTO $current, int $assessmentId, string $startUtc, string $endUtc, int $roomId, int $newSeats ): void {
		if ( ExamSessionStatus::Cancelled->value === $current->status ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сеанс отменён: изменить его нельзя.' );
		}

		$changed = $current->assessmentId !== $assessmentId || $current->scheduledAt !== $startUtc
			|| $current->plannedEndAt !== $endUtc || $current->roomId !== $roomId;

		if ( $changed && $current->isLocked() ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Сеанс уже начат: изменить можно только индивидуально.' );
		}
		// Перенос сеанса с записанными участниками — отдельная операция с причиной и уведомлением ({@see moveSession()}).
		if ( $changed && $current->occupiedCount > 0 ) {
			throw new CodedException( ErrorCode::ExamConflict, 'В сеансе есть участники: используйте перенос с причиной.' );
		}
		if ( $current->occupiedCount > $newSeats ) {
			throw new CodedException( ErrorCode::ExamConflict, sprintf( 'В сеансе уже занято %d мест, в кабинете их меньше.', $current->occupiedCount ) );
		}
	}

	/** Новый вариант опубликованного проведения получает запись в снимке: иначе его попытки считались бы по формату, а не по снимку. */
	private function addVariantToPublishedSnapshot( ExamEventDTO $event, int $assessmentId ): void {
		if ( ExamEventStatus::Published->value !== $event->status || null !== $event->snapshotFor( $assessmentId ) ) {
			return;
		}

		$snapshot                        = $this->snapshotOf( $event );
		$snapshot[ (string) $assessmentId ] = $this->buildSnapshot( $assessmentId );

		if ( ! $this->events->update( $event->id, array( 'variant_snapshot' => (string) wp_json_encode( $snapshot ) ), $event->version ) ) {
			throw $this->stale();
		}
	}

	/** @return array<string, mixed> */
	private function snapshotOf( ExamEventDTO $event ): array {
		$decoded = null === $event->variantSnapshot || '' === $event->variantSnapshot ? array() : json_decode( $event->variantSnapshot, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Перенос возможен, пока ни одна попытка не начата, сеанс открыт и вместимость нового кабинета вмещает занятые места. */
	private function assertSessionMovable( ExamSessionDTO $current, int $newSeats ): void {
		if ( $current->isLocked() ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Сеанс уже начат: перенести его нельзя.' );
		}
		if ( ExamSessionStatus::Open->value !== $current->status ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Сеанс отменён или завершён: перенести его нельзя.' );
		}
		if ( $current->occupiedCount > $newSeats ) {
			throw new CodedException( ErrorCode::ExamConflict, sprintf( 'В сеансе уже занято %d мест, в кабинете их меньше.', $current->occupiedCount ) );
		}
	}

	private function durationMinutes( ExamSessionDTO $session ): int {
		return max( 1, intdiv( $this->time->secondsUntil( $session->scheduledAt, $session->plannedEndAt ), 60 ) );
	}

	/**
	 * Закрывает участников отменённого сеанса: действующие записи отменяются с причиной (каждая в своей транзакции под блокировкой
	 * участия), брони гостей освобождаются. Запись с начатой попыткой отмену останавливает — это нарушение «сеанс не начат».
	 */
	private function closeParticipantsOf( int $actorUserId, int $sessionId, string $reason ): void {
		foreach ( $this->registrations->listBySession( $sessionId, array( ExamRegistrationStatus::Confirmed ) ) as $registration ) {
			if ( 1 === $registration->activeSlot ) {
				$this->registrationService->cancelByStaff( $actorUserId, $registration->id, $reason );
			}
		}
		foreach ( $this->guestApplications->listHeldIdsBySession( $sessionId ) as $applicationId ) {
			$this->holds->release( $applicationId, GuestApplicationState::Cancelled );
		}
	}

	/** Гостевая запись включается только при пройденном чек-листе запуска (11a.6.6); проведение без гостей чек-листу не подчиняется. */
	private function assertGuestLaunchReady( ExamEventDTO $event ): void {
		if ( ! $this->checklist->isReady( $event ) ) {
			throw new CodedException(
				ErrorCode::ExamConflict,
				trim( 'Запись гостей недоступна: настройки запуска не завершены. ' . $this->checklist->failedSummary( $event ) )
			);
		}
	}

	private function requireEvent( int $eventId ): ExamEventDTO {
		$event = $this->events->find( $eventId );
		if ( null === $event ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Проведение не найдено.' );
		}
		return $event;
	}

	/** Блокирующее чтение проведения — первый оператор транзакции. */
	private function lockEvent( int $eventId ): ExamEventDTO {
		$event = $this->events->findForUpdate( $eventId );
		if ( null === $event ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Проведение не найдено.' );
		}
		return $event;
	}

	private function reload( int $eventId ): ExamEventDTO {
		$event = $this->events->find( $eventId );
		if ( null === $event ) {
			throw new \RuntimeException( 'Проведение не найдено после сохранения.' );
		}
		return $event;
	}

	private function assertCanManage( int $actorUserId, ExamEventDTO $event ): void {
		if ( ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw $this->noAccess();
		}
	}

	private function assertEditable( ExamEventDTO $event ): void {
		if ( ! ( ExamEventStatus::tryFrom( $event->status )?->isEditable() ?? false ) ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Проведение завершено или отменено: изменить его нельзя.' );
		}
	}

	private function assertVersion( int $actual, int $expected ): void {
		if ( $actual !== $expected ) {
			throw $this->stale();
		}
	}

	private function noAccess(): CodedException {
		return new CodedException( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.' );
	}

	private function stale(): CodedException {
		return new CodedException( ErrorCode::ExamStale, 'Данные изменились: обновите страницу и повторите.' );
	}

	/** Дата `Y-m-d` строго: «2026-02-30» и «10.03.2026» отвергаются. */
	private function dateOf( mixed $value, string $message ): string {
		$raw  = trim( (string) $value );
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $raw ) {
			throw new CodedException( ErrorCode::ExamConflict, $message );
		}
		return $raw;
	}

	/** Время `H:i` строго. */
	private function timeOf( mixed $value ): string {
		$raw  = trim( (string) $value );
		$time = \DateTimeImmutable::createFromFormat( '!H:i', $raw );
		if ( false === $time || $time->format( 'H:i' ) !== $raw ) {
			throw new CodedException( ErrorCode::ExamConflict, 'Укажите время сеанса.' );
		}
		return $raw;
	}

	/** Местные дата и время (`Y-m-d H:i[:s]`, допускается разделитель `T` поля datetime-local) → UTC; пусто → null. */
	private function localDateTimeToUtc( mixed $value ): ?string {
		$raw = str_replace( 'T', ' ', trim( (string) $value ) );
		if ( '' === $raw ) {
			return null;
		}

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i' ) as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $raw );
			if ( false !== $parsed && $parsed->format( $format ) === $raw ) {
				return $this->time->toUtc( $parsed->format( 'Y-m-d H:i:s' ) );
			}
		}

		throw new CodedException( ErrorCode::ExamConflict, 'Неверный формат даты и времени записи.' );
	}
}
