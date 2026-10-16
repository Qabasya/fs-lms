<?php
/**
 * Полное удаление тестовой заявки гостя (с самим гостем и всеми его следами).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Exam\ExamLogAction;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestPurgeRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\TransactionRunner;

/**
 * В отличие от {@see ExamRetentionService::anonymizeByStaff()}, которое оставляет строку обезличенной, стирает гостя целиком:
 * заявки, записи, участия, ключи, сессии, согласия, события очереди и строки журнала «Экзамены». Для проверки формы по ссылкам
 * преподавателей: тестовая заявка не должна оставлять следов ни в отчёте школы, ни в списках.
 *
 * Удалить можно только заявку без последствий вовне: нет попыток, заказа оплаты, ручного урегулирования и школьного отчёта
 * ({@see ExamGuestPurgeRepository::blocker()}). Места, занятые записью или бронью, возвращаются сеансу. Заказы WooCommerce не трогаются.
 * Сам факт удаления пишется в журнал «Экзамены» без ФИО.
 */
class ExamGuestDeletionService {

	use TransactionRunner;

	/** Получает данные гостя, места сеансов и права сотрудника на проведения. */
	public function __construct(
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamGuestApplicationRepository $applications,
		private readonly ExamEventRepository $events,
		private readonly ExamAccessGuard $guard,
		private readonly ExamGuestPurgeRepository $purge,
		private readonly ExamParticipantNameService $names,
	) {}

	/**
	 * Удаляет гостя. Сотрудник должен управлять каждым проведением гостя (как при обезличивании).
	 *
	 * @throws CodedException Гость не найден или недоступен, либо у заявки есть последствия, из-за которых удалять нельзя.
	 */
	public function deleteByStaff( int $actorUserId, int $participantId ): void {
		$participant = $this->participants->find( $participantId );
		if ( null === $participant || null !== $participant->personId ) {
			throw new CodedException( ErrorCode::ExamAccess, 'Гость не найден.' );
		}

		$eventIds = array();
		foreach ( $this->participations->findByParticipant( $participantId ) as $participation ) {
			$event = $this->events->find( $participation->eventId );
			if ( null === $event || ! $this->guard->canManageEventGuests( $actorUserId, $event ) ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Гость не найден.' );
			}
			$eventIds[] = $participation->eventId;
		}

		// ФИО нужно для поиска строк журнала; читаем до удаления.
		$name = null === $participant->anonymizedAt ? $this->names->participantName( $participant ) : null;

		$this->inTransactionWithRetry(
			function () use ( $participantId, $name ): void {
				$locked = $this->participants->findForUpdate( $participantId );
				if ( null === $locked || null !== $locked->personId ) {
					throw new CodedException( ErrorCode::ExamNotFound, 'Гость не найден.' );
				}
				$blocker = $this->purge->blocker( $participantId );
				if ( null !== $blocker ) {
					throw new CodedException( ErrorCode::ExamState, $blocker );
				}

				$this->releaseSeats( $participantId );
				$this->purge->purge( $participantId, $name );
			}
		);

		ExamLogAction::GuestDeleted->record(
			array(
				'event_id'   => $eventIds[0] ?? null,
				'actor'      => $actorUserId,
				'context'    => array( 'events' => array_values( array_unique( $eventIds ) ) ),
			)
		);
	}

	/** Возвращает сеансам места действующих записей и броней гостя. */
	private function releaseSeats( int $participantId ): void {
		foreach ( $this->participations->findByParticipant( $participantId ) as $participation ) {
			if ( null === $participation->activeRegistrationId ) {
				continue;
			}
			$registration = $this->registrations->find( $participation->activeRegistrationId );
			if ( null !== $registration ) {
				$this->sessions->releaseSeat( $registration->sessionId );
			}
		}
		foreach ( $this->purge->heldApplications( $participantId ) as $held ) {
			if ( $this->applications->releaseHeldFlag( (int) $held['id'] ) ) {
				$this->sessions->releaseSeat( (int) $held['session_id'] );
			}
		}
	}
}
