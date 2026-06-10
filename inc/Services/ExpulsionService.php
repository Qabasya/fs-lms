<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\Enums\AuditAction;
use Inc\Enums\EnrollmentStatus;
use Inc\DTO\Enrollment\StudentRecordDTO;
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

	/**
	 * Отчисляет ученика из конкретной записи зачисления.
	 *
	 * @param int         $studentWpUserId WordPress user ID ученика
	 * @param string      $reason          Причина отчисления
	 * @param int|null    $recordId        ID записи student_records; если null — берётся первая активная
	 *
	 * @return array{
	 *   archive_id: int,
	 *   remaining_active_records: StudentRecordDTO[],
	 *   student_person_id: int,
	 * }
	 */
	public function expel( int $studentWpUserId, string $reason, ?int $recordId = null ): array {
		$studentPerson = $this->personRepository->findByWpUserId( $studentWpUserId );
		if ( null === $studentPerson ) {
			throw new RuntimeException( 'Студент не найден в системе.' );
		}

		if ( $recordId !== null ) {
			$record = $this->studentRecordRepository->find( $recordId );
			if ( null === $record || $record->studentPersonId !== $studentPerson->id ) {
				throw new RuntimeException( 'Запись зачисления не найдена.' );
			}
			if ( $record->status !== EnrollmentStatus::Active ) {
				throw new RuntimeException( 'Запись уже не активна.' );
			}
		} else {
			$records = $this->studentRecordRepository->findActiveByStudent( $studentPerson->id );
			if ( empty( $records ) ) {
				throw new RuntimeException( 'Активная запись ученика не найдена.' );
			}
			$record = $records[0];
		}

		$parentPerson = $record->parentPersonId !== null
			? $this->personRepository->find( $record->parentPersonId )
			: null;

		$now     = $this->clock->now( 'mysql', true );
		$actorId = get_current_user_id() ?: 0;

		$this->studentRecordRepository->setExpelled( $record->id, $now, $actorId, $reason ?: null );

		// Remaining active records после отчисления
		$remainingActive = $this->studentRecordRepository->findActiveByStudent( $studentPerson->id );

		if ( empty( $remainingActive ) ) {
			$this->personRepository->softDelete( $studentPerson->id );
			$this->userManager->delete( $studentWpUserId );
		}

		// Родитель: удалять только если у него не осталось активных учеников
		if ( $parentPerson !== null ) {
			$parentHasActive = ! empty(
				$this->studentRecordRepository->findActiveByParent( $parentPerson->id )
			);
			if ( ! $parentHasActive ) {
				$this->personRepository->softDelete( $parentPerson->id );
				if ( $parentPerson->wpUserId ) {
					$this->userManager->delete( $parentPerson->wpUserId );
				}
			}
		}

		$this->auditService->record(
			action:     AuditAction::StudentExpelled->value,
			targetType: 'person',
			targetId:   $studentPerson->id,
			details:    array(
				'record_id'  => $record->id,
				'had_parent' => null !== $parentPerson,
			),
		);

		return array(
			'archive_id'              => $record->id,
			'remaining_active_records' => $remainingActive,
			'student_person_id'       => $studentPerson->id,
		);
	}
}
