<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\RegistrationStatus;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Shared\TransactionRunner;

/**
 * Управление неявками на экзамене (6.3).
 *
 * @package Inc\Services\Exam
 */
class ExamNoShowService {

	public function __construct(
		private readonly TransactionRunner $transactionRunner,
		private readonly ExamParticipationRepository $participationRepo,
		private readonly ExamRegistrationRepository $registrationRepo,
		private readonly ExamSessionRepository $sessionRepo,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
	) {}

	/**
	 * Отметить неявку (вызывается внутри транзакции, участие заблокировано) (6.3.2).
	 *
	 * @param ExamParticipationDTO $participation Участие (заблокировано)
	 * @param ExamRegistrationDTO  $registration Запись
	 * @param ExamSessionDTO       $session Сеанс
	 *
	 * @return bool Была ли неявка отмечена
	 */
	public function markMissedLocked( ExamParticipationDTO $participation, ExamRegistrationDTO $registration, ExamSessionDTO $session ): bool {
		// Условия: запись действующая, нет попытки, сеанс закончился
		if ( ! $registration->active_slot || $participation->current_attempt_id ) {
			return false;
		}

		$nowUtc = $this->time->nowUtc();
		if ( $nowUtc < $session->planned_end_at ) {
			return false;
		}

		// Отменить запись
		if ( ! $this->registrationRepo->deactivate( $registration->id, RegistrationStatus::Missed, $nowUtc ) ) {
			return false;
		}

		// Освободить место и сбросить активную запись
		$this->registrationRepo->releaseSeat( $registration->session_id );
		$this->participationRepo->setActiveRegistration( $participation->id, null );

		// Событие
		$this->outbox->append( 'ParticipantMissed', array(
			'registration_id' => $registration->id,
			'session_id' => $registration->session_id,
		) );

		return true;
	}

	/**
	 * Отметить неявку (собственная транзакция) (6.3.3).
	 *
	 * @param int $registrationId ID записи
	 *
	 * @return bool Была ли неявка отмечена
	 */
	public function markMissed( int $registrationId ): bool {
		return $this->transactionRunner->inTransactionWithRetry( function () use ( $registrationId ): bool {
			$registration = $this->registrationRepo->find( $registrationId );
			if ( ! $registration ) {
				return false;
			}

			$participation = $this->participationRepo->findForUpdate( $registration->participation_id );
			if ( ! $participation ) {
				return false;
			}

			$session = $this->sessionRepo->find( $registration->session_id );
			if ( ! $session ) {
				return false;
			}

			return $this->markMissedLocked( $participation, $registration, $session );
		} );
	}
}
