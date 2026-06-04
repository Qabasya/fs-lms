<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\DTO\RelationshipDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\RelationType;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\RelationshipRepository;
use Inc\Services\AuditService;
use Inc\Shared\Traits\TransactionRunner;
use RuntimeException;

/**
 * Class RelationshipService
 *
 * Управление связями законный представитель ↔ ученик.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Добавление представителя** — идемпотентное создание связи через createIfNotExists.
 * 2. **Замена представителя** — атомарное завершение старой + создание новой связи в транзакции.
 * 3. **Завершение связи** — проставление valid_to.
 * 4. **Проверка доступа** — canRepresent() для авторизации родителя к данным ребёнка.
 *
 * ### Транзакционность:
 *
 * replaceRepresentative() оборачивается в TransactionRunner::inTransaction(),
 * чтобы terminate + create были атомарны.
 *
 * ### Инвариант активности:
 *
 * Связь активна если valid_from <= TODAY и (valid_to IS NULL OR valid_to > TODAY).
 */
class RelationshipService {

	use TransactionRunner;

	/**
	 * Конструктор сервиса.
	 *
	 * @param RelationshipRepository $relationshipRepository Репозиторий связей
	 * @param AuditService           $auditService           Сервис аудита
	 * @param UserManager            $userManager            Менеджер пользователей
	 */
	public function __construct(
		private readonly RelationshipRepository $relationshipRepository,
		private readonly AuditService           $auditService,
		private readonly UserManager            $userManager,
		private readonly ClockInterface         $clock,
	) {}

	/**
	 * Добавляет законного представителя ученику.
	 *
	 * Идемпотентен: если связь с тем же valid_from уже существует —
	 * возвращает её ID без создания дубля.
	 *
	 * @param int          $guardianPersonId ID person опекуна
	 * @param int          $studentPersonId  ID person ученика
	 * @param RelationType $type             Тип родства
	 * @param bool         $isPrimary        Является ли представитель основным
	 *
	 * @return int ID связи
	 */
	public function addRepresentative(
		int          $guardianPersonId,
		int          $studentPersonId,
		RelationType $type,
		bool         $isPrimary,
	): int {
		$today = $this->clock->now( 'Y-m-d' );

		$id = $this->relationshipRepository->createIfNotExists( array(
			'guardian_person_id' => $guardianPersonId,
			'student_person_id'  => $studentPersonId,
			'relation_type'      => $type->value,
			'valid_from'         => $today,
			'created_at'         => $this->clock->now( 'mysql', true ),
		) );

		$this->auditService->record(
			AuditAction::CreateRelationship->value,
			'relationship',
			$id,
			array(
				'guardian_person_id' => $guardianPersonId,
				'student_person_id'  => $studentPersonId,
				'relation_type'      => $type->value,
				'is_primary'         => $isPrimary,
			),
		);

		return $id;
	}

	/**
	 * Заменяет действующего представителя на нового.
	 *
	 * Атомарно: terminate(old) + create(new) в одной транзакции.
	 * Если транзакция падает — оба изменения откатываются.
	 *
	 * @param int          $oldRelationshipId  ID заменяемой связи
	 * @param int          $newGuardianPersonId ID person нового представителя
	 * @param RelationType $newType             Тип родства нового представителя
	 *
	 * @return int ID новой связи
	 *
	 * @throws RuntimeException Если старая связь не найдена
	 */
	public function replaceRepresentative(
		int          $oldRelationshipId,
		int          $newGuardianPersonId,
		RelationType $newType,
	): int {
		$old = $this->relationshipRepository->find( $oldRelationshipId );

		if ( null === $old ) {
			throw new RuntimeException( "Связь с ID {$oldRelationshipId} не найдена." );
		}

		$studentPersonId = $old->studentPersonId;
		$today           = $this->clock->now( 'Y-m-d' );

		$newId = $this->inTransaction( function () use ( $oldRelationshipId, $newGuardianPersonId, $newType, $studentPersonId, $today ): int {
			$this->relationshipRepository->terminate( $oldRelationshipId, $today );

			return $this->relationshipRepository->create( array(
				'guardian_person_id' => $newGuardianPersonId,
				'student_person_id'  => $studentPersonId,
				'relation_type'      => $newType->value,
				'valid_from'         => $today,
				'created_at'         => $this->clock->now( 'mysql', true ),
			) );
		} );

		$this->auditService->record(
			AuditAction::ReplaceRelationship->value,
			'relationship',
			$newId,
			array(
				'old_relationship_id'  => $oldRelationshipId,
				'new_guardian_person_id' => $newGuardianPersonId,
				'student_person_id'    => $studentPersonId,
				'new_relation_type'    => $newType->value,
			),
		);

		return $newId;
	}

	/**
	 * Завершает связь, проставляя valid_to.
	 *
	 * @param int    $relationshipId ID связи
	 * @param string $reason         Причина завершения (для audit log)
	 *
	 * @return void
	 *
	 * @throws RuntimeException Если связь не найдена
	 */
	public function terminate( int $relationshipId, string $reason ): void {
		$relationship = $this->relationshipRepository->find( $relationshipId );

		if ( null === $relationship ) {
			throw new RuntimeException( "Связь с ID {$relationshipId} не найдена." );
		}

		$this->relationshipRepository->terminate( $relationshipId );

		$this->auditService->record(
			AuditAction::TerminateRelationship->value,
			'relationship',
			$relationshipId,
			array( 'reason' => $reason ),
		);
	}

	/**
	 * Возвращает активных представителей ученика.
	 *
	 * @param int $studentPersonId ID person ученика
	 *
	 * @return RelationshipDTO[]
	 */
	public function getActiveRepresentatives( int $studentPersonId ): array {
		return $this->relationshipRepository->findActiveByStudent( $studentPersonId );
	}

	/**
	 * Возвращает активных подопечных представителя.
	 *
	 * @param int $guardianPersonId ID person представителя
	 *
	 * @return RelationshipDTO[]
	 */
	public function getActiveDependents( int $guardianPersonId ): array {
		return $this->relationshipRepository->findActiveByGuardian( $guardianPersonId );
	}

	/**
	 * Проверяет, имеет ли WP-пользователь право представлять данного ученика.
	 *
	 * Используется для авторизации родителя к данным ребёнка на уровне приложения:
	 * родитель может видеть только данные тех учеников, с которыми у него
	 * есть активная связь.
	 *
	 * @param int $guardianWpUserId  ID пользователя WP (родитель)
	 * @param int $studentPersonId   ID person ученика
	 *
	 * @return bool
	 */
	public function canRepresent( int $guardianWpUserId, int $studentPersonId ): bool {
		$guardianPersonId = $this->userManager->getPersonId( $guardianWpUserId );

		if ( null === $guardianPersonId ) {
			return false;
		}

		return $this->relationshipRepository->hasActiveRelationship( $guardianPersonId, $studentPersonId );
	}
}