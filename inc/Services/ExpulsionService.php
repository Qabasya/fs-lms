<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\ArchiveDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\EnrollmentStatus;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ArchiveRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use RuntimeException;

readonly class ExpulsionService {

	public function __construct(
		private PersonRepository     $personRepository,
		private EnrollmentRepository $enrollmentRepository,
		private ApplicationRepository $applicationRepository,
		private ArchiveRepository    $archiveRepository,
		private AuditService         $auditService,
		private PiiCryptoService     $crypto,
		private UserManager          $userManager,
		private ClockInterface       $clock,
	) {}

	public function expel( int $studentWpUserId, string $reason ): ArchiveDTO {
		$studentPerson = $this->personRepository->findByWpUserId( $studentWpUserId );
		if ( null === $studentPerson ) {
			throw new RuntimeException( 'Студент не найден в системе.' );
		}

		$enrollments = $this->enrollmentRepository->findActiveByStudent( $studentPerson->id );
		$enrollment  = ! empty( $enrollments ) ? $enrollments[0] : null;

		$archiveRecord = $enrollment !== null
			? $this->archiveRepository->findByEnrollmentId( $enrollment->id )
			: $this->archiveRepository->findActiveByStudent( $studentPerson->id );

		$parentPerson = null;
		if ( $archiveRecord !== null ) {
			$parentPerson = $this->personRepository->find( $archiveRecord->parentPersonId );
		}

		$now     = $this->clock->now( 'mysql', true );
		$actorId = get_current_user_id() ?: null;

		if ( $archiveRecord !== null ) {
			$this->archiveRepository->setExpelled( $archiveRecord->id, $now, $actorId ?? 0, $reason ?: null );
		}

		foreach ( $enrollments as $e ) {
			$this->enrollmentRepository->update( $e->id, array(
				'status'                => EnrollmentStatus::Expelled->value,
				'terminated_at'         => $now,
				'terminated_reason'     => $reason ?: null,
				'terminated_by_user_id' => $actorId,
				'updated_at'            => $now,
			) );
		}

		if ( $parentPerson ) {
			$this->personRepository->softDelete( $parentPerson->id );
		}
		$this->personRepository->softDelete( $studentPerson->id );

		if ( $parentPerson?->wpUserId ) {
			$this->userManager->delete( $parentPerson->wpUserId );
		}
		$this->userManager->delete( $studentWpUserId );

		$this->auditService->record(
			action:     AuditAction::StudentExpelled->value,
			targetType: 'person',
			targetId:   $studentPerson->id,
			details:    array(
				'archive_id'       => $archiveRecord?->id,
				'enrollment_count' => count( $enrollments ),
				'had_parent'       => null !== $parentPerson,
			),
		);

		if ( $archiveRecord !== null ) {
			$updated = $this->archiveRepository->find( $archiveRecord->id );
			if ( null !== $updated ) {
				return $updated;
			}
		}

		throw new RuntimeException( 'Ошибка обновления архивной записи.' );
	}

	private function buildSnapshotData( int $studentPersonId, ?object $enrollment ): array {
		if ( $enrollment?->snapshotEnc ) {
			try {
				$snapshot = json_decode( $this->crypto->decrypt( $enrollment->snapshotEnc ), true );
				if ( is_array( $snapshot ) ) {
					$snapshot['enrollment'] = array(
						'id'        => $enrollment->id,
						'group_id'  => $enrollment->groupId,
						'enrolled_at' => $enrollment->enrolledAt,
					);
					$snapshot['application_id'] = $enrollment->sourceApplicationId;

					return $snapshot;
				}
			} catch ( \Throwable ) {}
		}

		if ( $enrollment?->sourceApplicationId ) {
			$application = $this->applicationRepository->find( $enrollment->sourceApplicationId );
			if ( $application?->studentDataEnc ) {
				try {
					$studentData = json_decode( $this->crypto->decrypt( $application->studentDataEnc ), true );
					$parentData  = $application->parentDataEnc
						? json_decode( $this->crypto->decrypt( $application->parentDataEnc ), true )
						: null;

					return array(
						'student'        => $studentData ?? [],
						'guardian'       => $parentData ?? [],
						'enrollment'     => $enrollment ? array(
							'id'          => $enrollment->id,
							'group_id'    => $enrollment->groupId,
							'enrolled_at' => $enrollment->enrolledAt,
						) : [],
						'application_id' => $enrollment?->sourceApplicationId,
					);
				} catch ( \Throwable ) {}
			}
		}

		return array(
			'student'        => array( 'person_id' => $studentPersonId ),
			'guardian'       => [],
			'enrollment'     => [],
			'application_id' => null,
		);
	}
}
