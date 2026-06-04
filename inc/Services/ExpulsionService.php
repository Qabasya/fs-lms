<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\ExpelledArchiveDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\EnrollmentStatus;
use Inc\Managers\UserManager;
use Inc\Repositories\OptionsRepositories\StudentGroupMatrixRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\ExpelledArchiveRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\RelationshipRepository;
use RuntimeException;

/**
 * Class ExpulsionService
 *
 * Оркестрирует полный цикл отчисления студента:
 * 1. Собирает и шифрует данные в архивную запись.
 * 2. Обновляет статус зачисления.
 * 3. Удаляет студента из всех групп.
 * 4. Мягко удаляет записи persons.
 * 5. Физически удаляет WP-пользователей.
 * 6. Фиксирует событие в audit_log.
 */
readonly class ExpulsionService {

	public function __construct(
		private PersonRepository            $personRepository,
		private RelationshipRepository      $relationshipRepository,
		private EnrollmentRepository        $enrollmentRepository,
		private ApplicationRepository       $applicationRepository,
		private ExpelledArchiveRepository   $archiveRepository,
		private StudentGroupMatrixRepository $groupMatrix,
		private AuditService                $auditService,
		private PiiCryptoService            $crypto,
		private UserManager                 $userManager,
		private ClockInterface              $clock,
	) {}

	/**
	 * Отчисляет студента по его WP user ID.
	 *
	 * @param int    $studentWpUserId WP ID студента
	 * @param string $reason          Причина отчисления
	 *
	 * @return ExpelledArchiveDTO Созданная архивная запись
	 *
	 * @throws RuntimeException Если студент не найден или не имеет активного зачисления
	 */
	public function expel( int $studentWpUserId, string $reason ): ExpelledArchiveDTO {
		// 1. Найти person студента
		$studentPerson = $this->personRepository->findByWpUserId( $studentWpUserId );
		if ( null === $studentPerson ) {
			throw new RuntimeException( 'Студент не найден в системе.' );
		}

		// 2. Найти активные зачисления
		$enrollments = $this->enrollmentRepository->findActiveByStudent( $studentPerson->id );
		$enrollment  = ! empty( $enrollments ) ? $enrollments[0] : null;

		// 3. Найти родителя через отношения
		$relationships  = $this->relationshipRepository->findActiveByStudent( $studentPerson->id );
		$parentRelation = ! empty( $relationships ) ? $relationships[0] : null;
		$parentPerson   = $parentRelation
			? $this->personRepository->find( $parentRelation->guardianPersonId )
			: null;

		// 4. Собрать и зашифровать данные снимка
		$snapshotData = $this->buildSnapshotData( $studentPerson->id, $enrollment );
		$dataEnc      = $this->crypto->encrypt( wp_json_encode( $snapshotData ) );

		// 5. Создать архивную запись
		$now        = $this->clock->now( 'mysql', true );
		$actorId    = get_current_user_id() ?: null;
		$archiveId  = $this->archiveRepository->create( array(
			'enrollment_id'       => $enrollment?->id,
			'student_person_id'   => $studentPerson->id,
			'parent_person_id'    => $parentPerson?->id,
			'data_enc'            => $dataEnc,
			'expelled_at'         => $now,
			'expelled_by_user_id' => $actorId,
			'reason'              => $reason ?: null,
			'created_at'          => $now,
		) );

		// 6. Обновить статус всех активных зачислений
		foreach ( $enrollments as $e ) {
			$this->enrollmentRepository->update( $e->id, array(
				'status'                 => EnrollmentStatus::Expelled->value,
				'terminated_at'          => $now,
				'terminated_reason'      => $reason ?: null,
				'terminated_by_user_id'  => $actorId,
				'updated_at'             => $now,
			) );
		}

		// 7. Удалить из всех групп
		$groups = $this->groupMatrix->getGroupsByStudent( $studentWpUserId );
		foreach ( $groups as $groupId ) {
			$this->groupMatrix->removeStudent( $groupId, $studentWpUserId );
		}

		// 8. Мягко удалить persons
		if ( $parentPerson ) {
			$this->personRepository->softDelete( $parentPerson->id );
		}
		$this->personRepository->softDelete( $studentPerson->id );

		// 9. Удалить WP-пользователей
		if ( $parentPerson?->wpUserId ) {
			$this->userManager->delete( $parentPerson->wpUserId );
		}
		$this->userManager->delete( $studentWpUserId );

		// 10. Аудит
		$this->auditService->record(
			action:     AuditAction::StudentExpelled->value,
			targetType: 'person',
			targetId:   $studentPerson->id,
			details:    array(
				'archive_id'       => $archiveId,
				'enrollment_count' => count( $enrollments ),
				'had_parent'       => null !== $parentPerson,
			),
		);

		$archive = $this->archiveRepository->find( $archiveId );
		if ( null === $archive ) {
			throw new RuntimeException( 'Ошибка создания архивной записи.' );
		}

		return $archive;
	}

	/**
	 * Собирает данные снимка для архивной записи.
	 * Приоритет: snapshot из зачисления → данные заявки → пустые поля.
	 */
	private function buildSnapshotData( int $studentPersonId, ?object $enrollment ): array {
		// Приоритет: готовый snapshot из зачисления
		if ( $enrollment?->snapshotEnc ) {
			try {
				$snapshot = json_decode( $this->crypto->decrypt( $enrollment->snapshotEnc ), true );
				if ( is_array( $snapshot ) ) {
					$snapshot['enrollment'] = array(
						'id'          => $enrollment->id,
						'subject_key' => $enrollment->subjectKey,
						'period_key'  => $enrollment->periodKey,
						'group_id'    => $enrollment->groupId,
						'enrolled_at' => $enrollment->enrolledAt,
					);
					$snapshot['application_id'] = $enrollment->sourceApplicationId;

					return $snapshot;
				}
			} catch ( \Throwable ) {
				// Продолжаем к следующему источнику
			}
		}

		// Запасной вариант: данные из заявки
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
							'subject_key' => $enrollment->subjectKey,
							'period_key'  => $enrollment->periodKey,
							'group_id'    => $enrollment->groupId,
							'enrolled_at' => $enrollment->enrolledAt,
						) : [],
						'application_id' => $enrollment?->sourceApplicationId,
					);
				} catch ( \Throwable ) {
					// Продолжаем к пустому снимку
				}
			}
		}

		// Минимальный снимок (ученик без зачисления или заявки)
		return array(
			'student'        => array( 'person_id' => $studentPersonId ),
			'guardian'       => [],
			'enrollment'     => [],
			'application_id' => null,
		);
	}
}
