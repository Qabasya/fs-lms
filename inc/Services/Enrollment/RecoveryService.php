<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\DTO\Person\UserInputDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

readonly class RecoveryService {

	public function __construct(
		private ApplicationRepository        $applicationRepository,
		private StudentRecordRepository      $studentRecordRepository,
		private PersonRepository             $personRepository,
		private UserManager                  $userManager,
		private LogEventDispatcherInterface  $logEvents,
	) {}

	public function resolveStuckEnrollments(): int {
		$apps     = $this->applicationRepository->findStuckEnrolling( 5 );
		$resolved = 0;

		foreach ( $apps as $app ) {
			try {
				$record = null;
				if ( $app->studentPersonId !== null ) {
					$record = $this->studentRecordRepository->findActiveByStudentFirst( $app->studentPersonId );
				}

				if ( null === $record ) {
					$this->applicationRepository->setStatus( $app->id, ApplicationStatus::ReadyForReview );
					$resolved++;
					continue;
				}

				$person = $this->personRepository->find( $record->studentPersonId );

				if ( null !== $person && null === $person->wpUserId ) {
					$userId = $this->userManager->create( new UserInputDTO(
						userLogin:   'student_' . $person->id,
						userEmail:   '',
						userPass:    wp_generate_password( 64 ),
						displayName: $person->fullName(),
						firstName:   $person->firstName,
						lastName:    $person->lastName,
						role:        UserRole::FSStudent->value,
					) );

					$this->personRepository->setWpUser( $person->id, $userId );
					$this->userManager->setPersonId( $userId, $person->id );
				}

				$this->applicationRepository->markConverted( $app->id, $record->id );
				$this->logEvents->dispatch(
						LogEvent::ApplicationUpdated,
						new ApplicationStatusEvent( 0, AuditAction::RecoveryCompleted, $app->id )
					);
				$resolved++;
			} catch ( \Throwable ) {
				// Ошибка одной записи не прерывает обработку остальных
			}
		}

		return $resolved;
	}
}
