<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\StudentRecordDTO;
use Inc\Enums\AuditAction;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use RuntimeException;

readonly class ExpulsionService {

	public function __construct(
		private PersonRepository        $personRepository,
		private StudentRecordRepository $studentRecordRepository,
		private AuditService            $auditService,
		private UserManager             $userManager,
		private ClockInterface          $clock,
	) {}

	public function expel( int $studentWpUserId, string $reason ): StudentRecordDTO {
		$studentPerson = $this->personRepository->findByWpUserId( $studentWpUserId );
		if ( null === $studentPerson ) {
			throw new RuntimeException( 'Студент не найден в системе.' );
		}

		$records = $this->studentRecordRepository->findActiveByStudent( $studentPerson->id );

		if ( empty( $records ) ) {
			throw new RuntimeException( 'Активная запись ученика не найдена.' );
		}

		$record = $records[0];

		$parentPerson = $record->parentPersonId !== null
			? $this->personRepository->find( $record->parentPersonId )
			: null;

		$now     = $this->clock->now( 'mysql', true );
		$actorId = get_current_user_id() ?: 0;

		$this->studentRecordRepository->setExpelled( $record->id, $now, $actorId, $reason ?: null );

		if ( $parentPerson !== null ) {
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
				'record_id'  => $record->id,
				'had_parent' => null !== $parentPerson,
			),
		);

		$updated = $this->studentRecordRepository->find( $record->id );
		if ( null !== $updated ) {
			return $updated;
		}

		throw new RuntimeException( 'Ошибка обновления записи.' );
	}
}
