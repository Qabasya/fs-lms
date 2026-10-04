<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Неявка (6.3): если к плановому концу сеанса попытка не начата, действующая запись становится
 * «пропущено», место освобождается, и участник снова может записаться.
 *
 * Старт, отмена и неявка блокируют одну и ту же строку участия — поэтому у участника
 * всегда ровно один исход. Отметка прихода на неявку не влияет.
 */
class ExamNoShowService {

	use TransactionRunner;

	public function __construct(
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamOutbox $outbox,
		private readonly ExamTime $time,
	) {}

	/**
	 * Проставляет неявку. Участие уже заблокировано вызывающим кодом.
	 *
	 * @return bool true — неявка проставлена этим вызовом; повторный вызов вернёт false.
	 */
	public function markMissedLocked( ExamParticipationDTO $participation, ExamRegistrationDTO $registration, ExamSessionDTO $session ): bool {
		if ( 1 !== $registration->activeSlot || null !== $participation->currentAttemptId ) {
			return false;
		}

		$now = $this->time->nowUtc();
		if ( $now < $session->plannedEndAt ) {
			return false;
		}

		if ( ! $this->registrations->deactivate( $registration->id, ExamRegistrationStatus::Missed, $now ) ) {
			return false;
		}

		$this->sessions->releaseSeat( $session->id );
		$this->participations->setActiveRegistration( $participation->id, null );
		$this->outbox->add(
			ExamOutboxEvent::ParticipantMissed,
			'participation',
			$participation->id,
			$participation->version,
			array(
				'registration_id' => $registration->id,
				'session_id'      => $session->id,
			)
		);

		return true;
	}

	/**
	 * То же в собственной транзакции: блокировка участия, затем перечитывание под блокировкой.
	 * Участие определяется до транзакции — первым её оператором должна быть блокировка (иначе под REPEATABLE READ
	 * перечитывание вернёт состояние до блокировки).
	 */
	public function markMissed( int $registrationId ): bool {
		$participationId = $this->registrations->find( $registrationId )?->participationId;
		if ( null === $participationId ) {
			return false;
		}

		return (bool) $this->inTransactionWithRetry( function () use ( $registrationId, $participationId ): bool {
			$participation = $this->participations->findForUpdate( $participationId );
			$registration  = $this->registrations->find( $registrationId );
			if ( null === $participation || null === $registration ) {
				return false;
			}

			$session = $this->sessions->find( $registration->sessionId );
			if ( null === $session ) {
				return false;
			}

			return $this->markMissedLocked( $participation, $registration, $session );
		} );
	}
}
