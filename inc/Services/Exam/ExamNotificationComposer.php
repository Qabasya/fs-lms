<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamOutboxEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Profile\NotificationService;

/**
 * Превращает событие outbox в уведомления ленты кабинета (этап 9).
 *
 * **Только кабинет**: писем, SMS и мессенджеров нет. **Гостю LMS не отправляет ничего** — у участия с аудиторией `guest` нет получателей
 * ученика и родителя; уведомление ответственному о сдаче гостя остаётся (SPEC §10).
 *
 * **Идемпотентность.** Ключ дедупликации строится из самого события (`exam:{тип}:{агрегат}:{версия}`), поэтому повторная обработка той же
 * строки outbox ничего не вставляет. Исключения: утверждение (`exam:approved:{attempt}`, одно на попытку), исправление (`pushFresh`,
 * новая правка заменяет прежнюю плитку), продление (по новому дедлайну), лимит заявок (раз в час на источник).
 *
 * **Тексты** — на сервере ({@see NotificationService::toClientArray()}); в `payload` — готовые строки без ПД сверх необходимого.
 * До утверждения работы в уведомлениях ученику и родителю нет баллов.
 *
 * Сообщения об отмене сеанса и проведения ученикам идут от `RegistrationCancelled` (причина та же): `SessionCancelled` и `EventCancelled`
 * только снимают напоминания — иначе участник получил бы два уведомления об одном и том же.
 */
class ExamNotificationComposer {

	public function __construct(
		private readonly NotificationService $notifications,
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamRegistrationRepository $registrations,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamAudienceResolver $audience,
		private readonly ExamScoreService $scores,
		private readonly ExamConductService $conduct,
		private readonly RoomRepository $rooms,
		private readonly ExamTime $time,
		private readonly ExamOutbox $outbox,
		private readonly UserRepository $users,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamPaymentLinkRepository $paymentLinks,
		private readonly ExamSourceRepository $sources,
	) {}

	/** Обработка одной строки outbox. События без уведомлений (например, `AttemptStarted`) молча пропускаются. */
	public function handle( ExamOutboxEventDTO $row ): void {
		$payload = null !== $row->payload ? (array) json_decode( $row->payload, true ) : array();
		$type    = ExamOutboxEvent::tryFrom( $row->type );

		match ( $type ) {
			ExamOutboxEvent::EventPublished          => $this->scheduleOpening( $row ),
			ExamOutboxEvent::RegistrationOpened      => $this->registrationOpened( $row ),
			ExamOutboxEvent::RegistrationConfirmed   => $this->registrationConfirmed( $row, $payload ),
			ExamOutboxEvent::RegistrationTransferred => $this->registrationTransferred( $row, $payload ),
			ExamOutboxEvent::RegistrationCancelled   => $this->registrationCancelled( $row, $payload ),
			ExamOutboxEvent::ParticipantMissed       => $this->participantMissed( $row, $payload ),
			ExamOutboxEvent::AttemptSubmitted        => $this->attemptSubmitted( $row, $payload ),
			ExamOutboxEvent::AttemptApproved         => $this->attemptApproved( $row, $payload ),
			ExamOutboxEvent::ResultCorrected         => $this->resultCorrected( $row, $payload ),
			ExamOutboxEvent::AttemptExtended         => $this->attemptExtended( $row, $payload ),
			ExamOutboxEvent::SessionMoved            => $this->sessionMoved( $row, $payload ),
			ExamOutboxEvent::SessionCancelled        => $this->retractSession( (int) ( $payload['session_id'] ?? $row->aggregateId ) ),
			ExamOutboxEvent::EventCancelled          => $this->retractEvent( (int) ( $payload['event_id'] ?? $row->aggregateId ) ),
			ExamOutboxEvent::PaidNeedsResolution     => $this->paymentProblem( $row, $payload, NotificationType::ExamPaymentNeedsHelp, 'needs_help' ),
			ExamOutboxEvent::ReconcileFailed         => $this->paymentProblem( $row, $payload, NotificationType::ExamReconcileFailed, 'reconcile' ),
			ExamOutboxEvent::SourceLimitExceeded     => $this->sourceLimit( $payload ),
			default                                  => null,
		};
	}

	/**
	 * Напоминание по записи (вызывает {@see ExamReminderService}): `exam:{prefix}:{registration_id}`.
	 *
	 * @return bool false — получателей нет (гость или нет учётной записи).
	 */
	public function remind( NotificationType $type, string $prefix, ExamRegistrationDTO $registration ): bool {
		$participation = $this->participations->find( $registration->participationId );
		$session       = $this->sessions->find( $registration->sessionId );
		$event         = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $participation || null === $session || null === $event ) {
			return false;
		}

		$recipients = $this->studentRecipients( $participation );
		if ( array() === $recipients ) {
			return false;
		}

		$this->notifications->push(
			$recipients,
			$type,
			sprintf( 'exam:%s:%d', $prefix, $registration->id ),
			$this->sessionPayload( $session, $event ),
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);

		return true;
	}

	/** Снимает плитки напоминаний записи у ученика и родителей (отмена, перенос, неявка). */
	public function retractReminders( int $registrationId, ?ExamParticipationDTO $participation ): void {
		if ( null === $participation ) {
			return;
		}
		$recipients = $this->studentRecipients( $participation );
		foreach ( array( 'soon', 'tomorrow', 'entry' ) as $prefix ) {
			$this->notifications->retract( $recipients, sprintf( 'exam:%s:%d', $prefix, $registrationId ) );
		}
	}

	/** «Открыта запись»: получает каждый ученик аудитории и его родители один раз на проведение (ключ `exam:opened:{event}`). */
	public function announceOpening( ExamEventDTO $event ): void {
		$recipients = array();
		foreach ( $this->audience->studentPersonIds( $event->subjectKey ) as $personId ) {
			$recipients[] = $this->notifications->studentUserId( $personId );
			array_push( $recipients, ...$this->notifications->guardianUserIds( $personId ) );
		}

		$this->notifications->push(
			array_values( array_unique( array_map( 'intval', array_filter( $recipients ) ) ) ),
			NotificationType::ExamRegistrationOpened,
			'exam:opened:' . $event->id,
			array( 'event_title' => $event->title ),
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);
	}

	// ── События ──────────────────────────────────────────────────────────────────────────────────────

	/** Публикация: «открыта запись» — в момент открытия записи (или сразу, если запись уже открыта). */
	private function scheduleOpening( ExamOutboxEventDTO $row ): void {
		$event = $this->events->find( $row->aggregateId );
		if ( null === $event ) {
			return;
		}

		$opens = $event->registrationOpensAt;
		$this->outbox->add(
			ExamOutboxEvent::RegistrationOpened,
			'event',
			$event->id,
			$row->aggregateVersion,
			array( 'event_id' => $event->id ),
			null !== $opens && $opens > $this->time->nowUtc() ? $opens : null
		);
	}

	private function registrationOpened( ExamOutboxEventDTO $row ): void {
		$event = $this->events->find( $row->aggregateId );
		if ( null !== $event && 'published' === $event->status ) {
			$this->announceOpening( $event );
		}
	}

	/** @param array<string, mixed> $p */
	private function registrationConfirmed( ExamOutboxEventDTO $row, array $p ): void {
		$registration = $this->registrations->find( $row->aggregateId );
		$session      = $this->sessions->find( (int) ( $p['session_id'] ?? 0 ) );
		$this->toStudent( NotificationType::ExamRegistrationConfirmed, $row, 'registration_confirmed', (int) ( $p['participation_id'] ?? $registration?->participationId ?? 0 ), $session );
	}

	/** @param array<string, mixed> $p */
	private function registrationTransferred( ExamOutboxEventDTO $row, array $p ): void {
		$registration  = $this->registrations->find( $row->aggregateId );
		$participation = null !== $registration ? $this->participations->find( $registration->participationId ) : null;
		if ( null === $participation ) {
			return;
		}

		if ( isset( $p['old_registration_id'] ) ) {
			$this->retractReminders( (int) $p['old_registration_id'], $participation );
		}
		$this->toStudent( NotificationType::ExamRegistrationChanged, $row, 'registration_changed', $participation->id, $this->sessions->find( (int) ( $p['new_session_id'] ?? 0 ) ) );
	}

	/** @param array<string, mixed> $p */
	private function registrationCancelled( ExamOutboxEventDTO $row, array $p ): void {
		$registration  = $this->registrations->find( $row->aggregateId );
		$participation = null !== $registration ? $this->participations->find( $registration->participationId ) : null;
		$session       = $this->sessions->find( (int) ( $p['session_id'] ?? 0 ) );
		if ( null === $participation ) {
			return;
		}

		$this->retractReminders( $row->aggregateId, $participation );

		// Отмену сеанса или проведения участник видит под своим названием, причина у них общая с отменой записи.
		$event       = null !== $session ? $this->events->find( $session->eventId ) : null;
		$sessionGone = null !== $session && ( ExamSessionStatus::Cancelled->value === $session->status || 'cancelled' === ( $event->status ?? '' ) );
		$type        = $sessionGone ? NotificationType::ExamSessionCancelled : NotificationType::ExamRegistrationCancelled;

		$extra = 'staff' === ( $p['by'] ?? '' ) && isset( $p['reason'] ) ? array( 'reason' => (string) $p['reason'] ) : array();
		$this->toStudent( $type, $row, 'registration_cancelled', $participation->id, $session, $extra );
	}

	/** @param array<string, mixed> $p */
	private function participantMissed( ExamOutboxEventDTO $row, array $p ): void {
		$participation = $this->participations->find( $row->aggregateId );
		if ( null === $participation ) {
			return;
		}

		$this->retractReminders( (int) ( $p['registration_id'] ?? 0 ), $participation );
		$this->toStudent( NotificationType::ExamMissed, $row, 'missed', $participation->id, $this->sessions->find( (int) ( $p['session_id'] ?? 0 ) ) );
	}

	/** @param array<string, mixed> $p */
	private function attemptSubmitted( ExamOutboxEventDTO $row, array $p ): void {
		$participation = $this->participations->find( $row->aggregateId );
		$attempt       = $this->attempts->find( (int) ( $p['attempt_id'] ?? 0 ) );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $attempt || null === $event ) {
			return;
		}

		$registration = null !== $attempt->examRegistrationId ? $this->registrations->find( $attempt->examRegistrationId ) : null;
		$session      = null !== $registration ? $this->sessions->find( $registration->sessionId ) : null;
		$key          = sprintf( 'exam:%s:%d:%d', 'work_accepted', $row->aggregateId, $row->aggregateVersion );

		$this->notifications->push(
			$this->studentRecipients( $participation ),
			NotificationType::ExamWorkAccepted,
			$key,
			array( 'event_title' => $event->title ),
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);

		$participant = $this->participants->find( $participation->participantId );
		$responsible = $session->responsibleUserId ?? $event->ownerUserId;
		if ( null === $participant || $responsible <= 0 ) {
			return;
		}

		$name = $this->conduct->participantName( $participant ) . ( ExamAudience::Guest->value === $participation->audience ? ' (гость)' : '' );
		$this->notifications->push(
			array( $responsible ),
			NotificationType::ExamWorkSubmitted,
			sprintf( 'exam:%s:%d:%d', 'work_submitted', $row->aggregateId, $row->aggregateVersion ),
			array( 'event_title' => $event->title, 'participant_name' => $name ),
			null !== $session ? (string) add_query_arg( array( 'session' => $session->id ), $this->staffUrl( 'exam-conduct' ) ) : $this->staffUrl( 'exam-results' ),
			null,
			'exam_event',
			$event->id
		);
	}

	/** @param array<string, mixed> $p */
	private function attemptApproved( ExamOutboxEventDTO $row, array $p ): void {
		$attempt       = $this->attempts->find( (int) ( $p['attempt_id'] ?? 0 ) );
		$participation = $this->participations->find( (int) ( $p['participation_id'] ?? $row->aggregateId ) );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $attempt || null === $participation || null === $event ) {
			return;
		}

		$this->notifications->push(
			$this->studentRecipients( $participation ),
			NotificationType::ExamApproved,
			'exam:approved:' . $attempt->id,
			array( 'event_title' => $event->title, 'score_caption' => $this->scoreCaption( $this->scores->summarize( $attempt, $event ) ) ),
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);
	}

	/** @param array<string, mixed> $p */
	private function resultCorrected( ExamOutboxEventDTO $row, array $p ): void {
		$participation = $this->participations->find( (int) ( $p['participation_id'] ?? $row->aggregateId ) );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event ) {
			return;
		}

		$this->notifications->pushFresh(
			$this->studentRecipients( $participation ),
			NotificationType::ExamResultCorrected,
			'exam:corrected:' . (int) ( $p['attempt_id'] ?? 0 ),
			array( 'event_title' => $event->title, 'reason' => (string) ( $p['reason'] ?? '' ) ),
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);
	}

	/** @param array<string, mixed> $p */
	private function attemptExtended( ExamOutboxEventDTO $row, array $p ): void {
		$participation = $this->participations->find( $row->aggregateId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		$deadline      = (string) ( $p['deadline_at'] ?? '' );
		if ( null === $participation || null === $event || '' === $deadline ) {
			return;
		}

		$this->notifications->push(
			$this->studentRecipients( $participation ),
			NotificationType::ExamExtended,
			sprintf( 'exam:extended:%d:%s', (int) ( $p['attempt_id'] ?? 0 ), gmdate( 'YmdHi', (int) strtotime( $deadline . ' UTC' ) ) ),
			array( 'event_title' => $event->title, 'time_end' => substr( $deadline, 11, 5 ), 'reason' => (string) ( $p['reason'] ?? '' ) ),
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);
	}

	/** @param array<string, mixed> $p */
	private function sessionMoved( ExamOutboxEventDTO $row, array $p ): void {
		$session = $this->sessions->find( (int) ( $p['session_id'] ?? $row->aggregateId ) );
		$event   = null !== $session ? $this->events->find( $session->eventId ) : null;
		if ( null === $session || null === $event ) {
			return;
		}

		$payload = $this->sessionPayload( $session, $event ) + array( 'reason' => (string) ( $p['reason'] ?? '' ) );
		foreach ( $this->registrations->listBySession( $session->id, array( ExamRegistrationStatus::Confirmed ) ) as $registration ) {
			$participation = $this->participations->find( $registration->participationId );
			if ( null === $participation ) {
				continue;
			}

			// Запись та же, время новое: прежние напоминания снимаются, новые придут по новому времени.
			$this->retractReminders( $registration->id, $participation );
			$this->notifications->push(
				$this->studentRecipients( $participation ),
				NotificationType::ExamSessionMoved,
				sprintf( 'exam:session_moved:%d:%d', $session->id, $row->aggregateVersion ),
				$payload,
				$this->learnerUrl( $event->id ),
				null,
				'exam_event',
				$event->id
			);
		}
	}

	private function retractSession( int $sessionId ): void {
		foreach ( $this->registrations->listBySession( $sessionId ) as $registration ) {
			$this->retractReminders( $registration->id, $this->participations->find( $registration->participationId ) );
		}
	}

	private function retractEvent( int $eventId ): void {
		foreach ( $this->sessions->findByEvent( $eventId ) as $session ) {
			$this->retractSession( $session->id );
		}
	}

	/** @param array<string, mixed> $p */
	private function paymentProblem( ExamOutboxEventDTO $row, array $p, NotificationType $type, string $prefix ): void {
		$application = $this->applications->find( (int) ( $p['application_id'] ?? $row->aggregateId ) );
		$event       = null !== $application ? $this->events->find( $application->eventId ) : null;
		if ( null === $application || null === $event ) {
			return;
		}

		$links = $this->paymentLinks->findByApplication( $application->id );
		$order = array() !== $links ? (string) $links[0]->wcOrderId : '';
		$this->notifications->push(
			$this->paymentRecipients(),
			$type,
			sprintf( 'exam:%s:%d:%d', $prefix, $application->id, $row->aggregateVersion ),
			// Телефон и контакты гостя в payload не кладутся; ФИО до этапа 11a недоступно — «Гость».
			array( 'event_title' => $event->title, 'order_number' => $order, 'participant_name' => 'Гость' ),
			$this->staffUrl( 'exam-payments' ),
			null,
			'exam_event',
			$event->id
		);
	}

	/** @param array<string, mixed> $p */
	private function sourceLimit( array $p ): void {
		$event  = $this->events->find( (int) ( $p['event_id'] ?? 0 ) );
		$source = $this->sources->find( (int) ( $p['source_id'] ?? 0 ) );
		if ( null === $event || null === $source ) {
			return;
		}

		$this->notifications->push(
			array_merge( array( $event->ownerUserId ), $this->paymentRecipients() ),
			NotificationType::ExamSourceLimit,
			sprintf( 'exam:source_limit:%d:%s', $source->id, gmdate( 'YmdH', (int) strtotime( $this->time->nowUtc() . ' UTC' ) ) ),
			array( 'event_title' => $event->title, 'source_label' => $source->schoolName . ' · ' . $source->grade . ' класс' ),
			$this->staffUrl( 'exam-plan' ),
			null,
			'exam_event',
			$event->id
		);
	}

	// ── Получатели и тексты ──────────────────────────────────────────────────────────────────────────

	/**
	 * Администраторы платформы (роль `lms_office`); если таких нет — пользователи с правом администратора WordPress.
	 *
	 * @return int[]
	 */
	public function paymentRecipients(): array {
		$ids = $this->notifications->adminUserIds();
		if ( array() !== $ids ) {
			return $ids;
		}

		return array_map( static fn ( $user ): int => $user->id, $this->users->getByCapability( Capability::Admin ) );
	}

	/**
	 * Ученик и его родители. **Гостю — пусто**: LMS ему не отправляет ничего.
	 *
	 * @return int[]
	 */
	private function studentRecipients( ExamParticipationDTO $participation ): array {
		if ( ExamAudience::Guest->value === $participation->audience ) {
			return array();
		}
		$participant = $this->participants->find( $participation->participantId );
		if ( null === $participant || null === $participant->personId ) {
			return array();
		}

		$ids = array_merge(
			array( $this->notifications->studentUserId( $participant->personId ) ),
			$this->notifications->guardianUserIds( $participant->personId )
		);

		return array_values( array_unique( array_map( 'intval', array_filter( $ids ) ) ) );
	}

	/**
	 * Уведомление ученику и родителям по участию; ключ — из самого события.
	 *
	 * @param array<string, mixed> $extra Дополнительные поля payload (например, причина).
	 */
	private function toStudent( NotificationType $type, ExamOutboxEventDTO $row, string $tag, int $participationId, ?ExamSessionDTO $session, array $extra = array() ): void {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event ) {
			return;
		}

		$payload = null !== $session ? $this->sessionPayload( $session, $event ) : array( 'event_title' => $event->title );
		$this->notifications->push(
			$this->studentRecipients( $participation ),
			$type,
			sprintf( 'exam:%s:%d:%d', $tag, $row->aggregateId, $row->aggregateVersion ),
			$extra + $payload,
			$this->learnerUrl( $event->id ),
			null,
			'exam_event',
			$event->id
		);
	}

	/** @return array<string, string> */
	private function sessionPayload( ExamSessionDTO $session, ExamEventDTO $event ): array {
		$start = $this->time->toLocal( $session->scheduledAt );
		$end   = $this->time->toLocal( $session->plannedEndAt );

		return array(
			'event_title' => $event->title,
			'date'        => gmdate( 'd.m.Y', (int) strtotime( substr( $start, 0, 10 ) . ' UTC' ) ),
			'time'        => substr( $start, 11, 5 ),
			'time_end'    => substr( $end, 11, 5 ),
			'room'        => $this->rooms->find( $session->roomId )->name ?? '',
		);
	}

	/**
	 * Готовая строка итога: ЕГЭ — «84 из 100», ОГЭ — «15 из 19, отметка 4»; без вторичного балла — первичный.
	 *
	 * @param array<string, mixed> $r Итог {@see ExamScoreService::summarize()}.
	 */
	private function scoreCaption( array $r ): string {
		$primary = sprintf( '%d из %d', $r['primary'], $r['primary_max'] );
		if ( 'oge' === $r['direction'] ) {
			return ! empty( $r['final'] ) && null !== $r['grade'] ? sprintf( '%s, отметка %d', $primary, $r['grade'] ) : $primary;
		}

		return ! empty( $r['final'] ) && null !== $r['secondary'] ? sprintf( '%d из %d', $r['secondary'], $r['secondary_max'] ) : $primary;
	}

	private function learnerUrl( int $eventId ): string {
		return (string) add_query_arg( array( 'event' => $eventId ), PageRoutes::UserProfile->screenUrl( 'learner-exams' ) );
	}

	private function staffUrl( string $screen ): string {
		return PageRoutes::UserProfile->screenUrl( $screen );
	}
}
