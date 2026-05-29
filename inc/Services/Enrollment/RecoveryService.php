<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Enums\ApplicationStatus;
use Inc\Enums\UserRole;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;

readonly class RecoveryService {

	public function __construct(
		private ApplicationRepository $applicationRepository,
		private EnrollmentRepository  $enrollmentRepository,
		private PersonRepository      $personRepository,
		private UserManager           $userManager,
		private AuditService          $auditService,
	) {}

	public function resolveStuckEnrollments(): int {
		$apps     = $this->applicationRepository->findStuckEnrolling( 5 );
		$resolved = 0;

		foreach ( $apps as $app ) {
			try {
				$enrollment = $this->enrollmentRepository->findBySourceApplication( $app->id );

				if ( null === $enrollment ) {
					$this->applicationRepository->setStatus( $app->id, ApplicationStatus::ReadyForReview );
					$resolved++;
					continue;
				}

				$person = $this->personRepository->find( $enrollment->studentPersonId );

				if ( null !== $person && null === $person->wpUserId ) {
					$email = $person->email ?? '';
					$existingUser = $email !== '' ? $this->userManager->findByEmail( $email ) : null;

					if ( null !== $existingUser ) {
						$userId = $existingUser->ID;
					} else {
						$userId = $this->userManager->create( array(
							'user_login'   => $email !== '' ? $email : 'student_' . $person->id,
							'user_email'   => $email,
							'user_pass'    => wp_generate_password( 64 ),
							'display_name' => '',
							'role'         => UserRole::FSStudent->value,
						) );
					}

					$this->personRepository->setWpUser( $person->id, $userId );
					$this->userManager->setPersonId( $userId, $person->id );
				}

				$this->applicationRepository->markConverted( $app->id, $enrollment->id );

				$this->auditService->record( 'recovery_completed', 'application', $app->id );

				$resolved++;
			} catch ( \Throwable ) {
				// Ошибка одной записи не прерывает обработку остальных
			}
		}

		return $resolved;
	}
}