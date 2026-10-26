<?php
/**
 * Команды сотрудника и допуск к проверке экзаменной попытки.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Access\Capability;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamResultStatus;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;

/** Команды сотрудника и допуск к проверке экзаменной попытки. */
class ExamConductService {

	/** Получает команды участия и общие правила состояния; доску и экспорт строит отдельный view-сервис. */
	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamResultStatusService $resultStates,
		private readonly ExamParticipantNameService $names,
		private readonly ExamScoreService $scores,
		private readonly ExamAccessGuard $guard,
		private readonly ExamTime $time,
		private readonly ExamReviewProjection $reviews,
		private readonly ExamAccessTokenService $tokens,
		private readonly PluginConfig $config,
		private readonly LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Вправе ли пользователь работать с экзаменной попыткой (проверять, оценивать, утверждать, открывать): единственное решение для
	 * коллбеков «Работ». Не экзаменная попытка сюда не относится — для неё отвечает прежняя проверка группы.
	 */
	public function canManageAttempt( int $userId, AttemptDTO $attempt ): bool {
		if ( null === $attempt->examParticipationId ) {
			return false;
		}
		$participation = $this->participations->find( $attempt->examParticipationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;

		return null !== $event && $this->guard->canManageEvent( $userId, $event );
	}

	/**
	 * Разбор экзаменной попытки для экрана проверки (8.4.2): режим `manage` плюс итог, имя участника и состояние результата.
	 *
	 * @return array<string, mixed>|null null — попытка не найдена или не экзаменная.
	 *
	 * @throws CodedException `ExamAccess` — нет права на проведение попытки.
	 */
	public function reviewFor( int $actorUserId, AttemptDTO $attempt ): ?array {
		if ( ! $this->canManageAttempt( $actorUserId, $attempt ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Работа не найдена.' );
		}

		$participation = $this->participations->find( (int) $attempt->examParticipationId );
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		$view          = $this->reviews->forViewer( $attempt->id, ExamReviewProjection::MODE_MANAGE );
		if ( null === $participation || null === $participant || null === $event || null === $view ) {
			return null;
		}

		$audience = ExamAudience::from( $participation->audience );
		$status   = $this->resultStates->status( $attempt, $audience )->value;

		unset( $view['group_id'] );

		return $view + array(
			'exam'                => true,
			'audience'            => $audience->value,
			'participant_name'    => $this->names->participantName( $participant ),
			'result'              => $this->scores->summarize( $attempt, $event ),
			'result_status'       => $status,
			'result_status_label' => $this->resultLabel( $status, $audience ),
			'approvable'          => ExamResultStatus::Ready->value === $status,
			'correctable'         => $attempt->isApproved(),
		);
	}

	/**
	 * Допуск гостя (8.8.2): ставится выдачей ссылки входа ({@see self::issueEntryLink()}), снимается здесь вместе с отзывом ссылки.
	 * Снять допуск можно, пока попытка не начата.
	 *
	 * @throws CodedException Нет доступа к гостю, действующей записи нет либо попытка уже начата.
	 */
	public function admit( int $actorUserId, int $participationId, bool $admitted ): void {
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );
		if ( $admitted && null === $this->activeRegistrationOf( $participation ) ) {
			throw new CodedException( ErrorCode::ExamNotFound, 'У участника нет действующей записи.' );
		}
		if ( $participation->hasAttempt() ) {
			throw new CodedException( ErrorCode::ExamStarted, 'Попытка уже начата: допуск изменить нельзя.' );
		}

		$this->participations->setAdmission( $participationId, $admitted ? $this->time->nowUtc() : null, $admitted ? $actorUserId : null );
		if ( ! $admitted ) {
			$this->tokens->revoke( ExamTokenPurpose::Entry, $participationId );
		}

		ExamLogAction::Admitted->record(
			array(
				'event_id'         => $participation->eventId,
				'participation_id' => $participationId,
				'registration_id'  => $participation->activeRegistrationId,
				'actor'            => $actorUserId,
				'context'          => array( 'admitted' => $admitted ),
			)
		);
	}

	/**
	 * Ручное подтверждение записи гостя («Запись подтверждена»): операционная отметка сотрудника, как и приход.
	 * На допуск, ссылку входа и попытку не влияет; ставить можно только при действующей записи, снимать — всегда.
	 *
	 * @throws CodedException Нет доступа к гостю либо (при установке) нет действующей записи.
	 */
	public function confirmGuest( int $actorUserId, int $participationId, bool $confirmed ): void {
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );
		if ( $confirmed && null === $this->activeRegistrationOf( $participation ) ) {
			throw new CodedException( ErrorCode::ExamNotFound, 'У участника нет действующей записи.' );
		}

		$this->participations->setStaffConfirmation( $participationId, $confirmed ? $this->time->nowUtc() : null, $confirmed ? $actorUserId : null );

		ExamLogAction::GuestConfirmed->record(
			array(
				'event_id'         => $participation->eventId,
				'participation_id' => $participationId,
				'registration_id'  => $participation->activeRegistrationId,
				'actor'            => $actorUserId,
				'context'          => array( 'confirmed' => $confirmed ),
			)
		);
	}

	/**
	 * Ручная отметка «Оповещение отправлено» (список «Гости экзамена»): предшествует подтверждению записи.
	 * Снятие оповещения снимает и подтверждение — подтверждённой запись без оповещения не бывает.
	 *
	 * @throws CodedException Нет доступа к гостю.
	 */
	public function notifyGuest( int $actorUserId, int $participationId, bool $notified ): void {
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );

		$this->participations->setStaffNotification( $participationId, $notified ? $this->time->nowUtc() : null, $notified ? $actorUserId : null );
		if ( ! $notified && null !== $participation->staffConfirmedAt ) {
			$this->participations->setStaffConfirmation( $participationId, null, null );
		}

		ExamLogAction::GuestNotified->record(
			array(
				'event_id'         => $participation->eventId,
				'participation_id' => $participationId,
				'actor'            => $actorUserId,
				'context'          => array( 'notified' => $notified ),
			)
		);
	}

	/**
	 * Ссылка входа гостя (11b.1.2): действующая запись обязательна, сеанс не закончился; выдача допускает гостя, если он ещё не допущен. Прежний ключ отзывается, поколение растёт.
	 * Права `ExportPII` и `ManageLmsPlatform` не нужны: ссылка не содержит персональных данных.
	 *
	 * @return string Адрес с ключом; позднее ту же ссылку копирует кнопка строки доски (ключ входа хранится зашифрованным).
	 *
	 * @throws CodedException Гость недоступен, нет действующей записи или допуска либо сеанс закончился.
	 */
	public function issueEntryLink( int $actorUserId, int $participationId ): string {
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );
		$registration  = $this->activeRegistrationOf( $participation );
		if ( null === $registration ) {
			throw new CodedException( ErrorCode::ExamNotFound, 'У участника нет действующей записи.' );
		}
		$session = $this->sessions->find( $registration->sessionId );
		if ( null === $session || $session->plannedEndAt <= $this->time->nowUtc() ) {
			throw new CodedException( ErrorCode::ExamClosed, 'Сеанс уже закончился.' );
		}

		if ( null === $participation->admittedAt ) {
			$this->admit( $actorUserId, $participationId, true );
		}

		$plain = $this->tokens->issue( ExamTokenPurpose::Entry, $participationId, $actorUserId, $session->plannedEndAt );

		ExamLogAction::EntryLinkIssued->record(
			array(
				'event_id'         => $participation->eventId,
				'session_id'       => $session->id,
				'participation_id' => $participationId,
				'registration_id'  => $registration->id,
				'actor'            => $actorUserId,
			)
		);

		return PageRoutes::ExamEntry->url() . '?k=' . $plain;
	}

	/**
	 * Личная ссылка результата (11b.5.1): право `ShareExamResults` и управление проведением; участие гостевое, работа сдана.
	 * Срок ключа — конец проведения + срок хранения гостевых данных. Открытый ключ не хранится: «скопировать ещё раз» = перевыпуск,
	 * прежняя ссылка перестаёт работать. На идущий экзамен и ссылку входа не влияет (другое назначение).
	 *
	 * @return string Адрес с ключом — единственный раз.
	 *
	 * @throws CodedException Нет права поделиться результатом, участие недоступно либо работа ещё не сдана.
	 */
	public function issueResultLink( int $actorUserId, int $participationId ): string {
		$participation = $this->resultLinkParticipation( $actorUserId, $participationId );
		$attempt       = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
		if ( null === $attempt || AttemptStatus::InProgress === $attempt->status ) {
			throw new CodedException( ErrorCode::ExamState, 'Ссылку результата можно выдать после сдачи работы.' );
		}

		$event   = $this->events->find( $participation->eventId );
		$expires = $this->time->addMinutes( $this->time->endOfLocalDayUtc( (string) $event?->periodTo ), $this->config->examGuestRetentionDays() * 1440 );
		$plain   = $this->tokens->issue( ExamTokenPurpose::Result, $participationId, $actorUserId, $expires );

		$this->logEvents->dispatch( LogEvent::ExamGuestLinkIssued, new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::ExamParticipation, $participationId, 'ссылка результата' ) );
		ExamLogAction::ResultLinkIssued->record( array( 'event_id' => $participation->eventId, 'participation_id' => $participationId, 'attempt_id' => $attempt->id, 'actor' => $actorUserId ) );

		return PageRoutes::ExamResult->url() . '?k=' . $plain;
	}

	/**
	 * Отзыв ссылки результата без нового ключа: после отзыва страница открывается только 404. Сессию входа не затрагивает.
	 *
	 * @throws CodedException Нет права поделиться результатом или гостевое участие недоступно.
	 */
	public function revokeResultLink( int $actorUserId, int $participationId ): void {
		$participation = $this->resultLinkParticipation( $actorUserId, $participationId );
		$this->tokens->revoke( ExamTokenPurpose::Result, $participationId );

		$this->logEvents->dispatch( LogEvent::ExamGuestLinkRevoked, new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::ExamParticipation, $participationId, 'ссылка результата' ) );
		ExamLogAction::ResultLinkRevoked->record( array( 'event_id' => $participation->eventId, 'participation_id' => $participationId, 'actor' => $actorUserId ) );
	}

	/**
	 * Участие гостя для действий со ссылкой результата: нужен `ShareExamResults` (не `ManageExamGuests`) и управление проведением.
	 *
	 * @throws CodedException Участие отсутствует, не гостевое или у пользователя нет права и доступа к проведению.
	 */
	private function resultLinkParticipation( int $actorUserId, int $participationId ): ExamParticipationDTO {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event || ! user_can( $actorUserId, Capability::ShareExamResults->value ) || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Участие не найдено.' );
		}
		if ( ExamAudience::Guest->value !== $participation->audience ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Это действие доступно только для гостей.' );
		}

		return $participation;
	}

	/**
	 * Ручная отметка «Ссылка передана» (8.8.6): ставит сотрудник после того, как отдал ссылку входа или результата. **Копирование ссылки отметку не ставит.**
	 *
	 * @throws CodedException Участие недоступно, назначение ссылки не поддерживается или действующий ключ не выдан.
	 */
	public function markLinkPassed( int $actorUserId, int $participationId, ExamTokenPurpose $purpose ): void {
		if ( ! in_array( $purpose, array( ExamTokenPurpose::Entry, ExamTokenPurpose::Result ), true ) ) {
			throw new CodedException( ErrorCode::ExamState, 'Эту ссылку отметить нельзя.' );
		}
		$participation = $this->guestParticipationFor( $actorUserId, $participationId );

		$token = $this->tokens->activeToken( $purpose, $participationId );
		if ( null === $token ) {
			throw new CodedException( ErrorCode::ExamState, 'Сначала выдайте ссылку.' );
		}
		$this->tokens->markPassed( $token->id, $actorUserId );

		ExamLogAction::LinkMarkedPassed->record( array( 'event_id' => $participation->eventId, 'participation_id' => $participationId, 'actor' => $actorUserId, 'context' => array( 'purpose' => $purpose->value ) ) );
	}

	/**
	 * Участие гостя, которым сотрудник вправе управлять (право на гостей + проведение). Чужое и ученическое — «не найдено».
	 *
	 * @throws CodedException Участие отсутствует, не гостевое или управление гостями проведения недоступно пользователю.
	 */
	private function guestParticipationFor( int $actorUserId, int $participationId ): ExamParticipationDTO {
		$participation = $this->participations->find( $participationId );
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $participation || null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Участие не найдено.' );
		}
		if ( ExamAudience::Guest->value !== $participation->audience ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Это действие доступно только для гостей.' );
		}

		return $participation;
	}

	/**
	 * Возвращает только текущую подтверждённую запись с действующим слотом.
	 */
	private function activeRegistrationOf( ExamParticipationDTO $participation ): ?ExamRegistrationDTO {
		$registration = null !== $participation->activeRegistrationId ? $this->registrations->find( $participation->activeRegistrationId ) : null;

		return null !== $registration && 1 === $registration->activeSlot && ExamRegistrationStatus::Confirmed->value === $registration->status ? $registration : null;
	}

	/**
	 * Отметка прихода: атрибут операционный, на допуск и неявку не влияет. Повторная отметка перезаписывает автора и время.
	 *
	 * @throws CodedException Запись отсутствует, недействительна или проведение недоступно пользователю.
	 */
	public function markArrival( int $actorUserId, int $registrationId, bool $arrived ): void {
		$registration  = $this->registrations->find( $registrationId );
		$participation = null !== $registration ? $this->participations->find( $registration->participationId ) : null;
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $registration || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Запись не найдена.' );
		}
		if ( 1 !== $registration->activeSlot ) {
			throw new CodedException( ErrorCode::ExamState, 'Запись не действует.' );
		}

		$this->registrations->setArrival(
			$registrationId,
			$arrived ? $this->time->nowUtc() : null,
			$arrived ? $actorUserId : null
		);

		ExamLogAction::Arrival->record(
			array(
				'event_id'         => $event->id,
				'session_id'       => $registration->sessionId,
				'registration_id'  => $registrationId,
				'participation_id' => $participation->id,
				'actor'            => $actorUserId,
				'context'          => array( 'arrived' => $arrived ),
			)
		);
	}

	/**
	 * Удаляет отменённую запись нашего ученика: она ничего не занимает и ни на что не ссылается, только засоряет список.
	 * Гостей это не касается (у них своё полное удаление заявки), записи с попыткой и действующие не удаляются.
	 *
	 * @throws CodedException Запись недоступна сотруднику, не отменена, гостевая либо по ней есть попытка.
	 */
	public function deleteCancelledRegistration( int $actorUserId, int $registrationId ): void {
		$registration  = $this->registrations->find( $registrationId );
		$participation = null !== $registration ? $this->participations->find( $registration->participationId ) : null;
		$event         = null !== $participation ? $this->events->find( $participation->eventId ) : null;
		if ( null === $registration || null === $participation || null === $event || ! $this->guard->canManageEvent( $actorUserId, $event ) ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Запись не найдена.' );
		}
		if ( ExamAudience::Student->value !== $participation->audience ) {
			throw new CodedException( ErrorCode::ExamState, 'Запись гостя удаляется вместе с заявкой.' );
		}
		if ( ! $this->registrations->deleteCancelled( $registrationId ) ) {
			throw new CodedException( ErrorCode::ExamState, 'Удалить можно только отменённую запись без начатой работы.' );
		}

		ExamLogAction::RegistrationDeleted->record(
			array(
				'event_id'         => $event->id,
				'session_id'       => $registration->sessionId,
				'registration_id'  => $registrationId,
				'participation_id' => $participation->id,
				'actor'            => $actorUserId,
			)
		);
	}

	/**
	 * Получает подпись общего состояния результата с учётом аудитории.
	 */
	private function resultLabel( string $result, ExamAudience $audience ): string {
		return ExamResultStatus::from( $result )->label( $audience );
	}
}
