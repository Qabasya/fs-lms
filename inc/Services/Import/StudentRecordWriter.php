<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Person\PersonService;

/**
 * Class StudentRecordWriter
 *
 * Общая для {@see StudentRowImporter} и `EnrolledStudentRowImporter` логика
 * резолва/создания группы и persons — чтобы не дублировать её между
 * архивным и enrolled-импортёрами.
 *
 * ### Два раздельных шага (важно для дедупликации записи по договору)
 *
 * resolve*() — только чтение, не создаёт ничего. Импортёр сначала резолвит
 * (может вернуть null), затем проверяет дедуп по договору
 * ({@see \Inc\Repositories\WPDBRepositories\StudentRecordRepository::existsByContract()})
 * и только если строка не дубль и не dry-run — вызывает create*() для того,
 * что осталось не найденным. Объединение resolve+create в один вызов
 * заставило бы создавать группу/person ещё до проверки дедупа.
 */
readonly class StudentRecordWriter {

	public function __construct(
		private GroupsRepository     $groups,
		private PersonImportResolver $personResolver,
		private PersonService        $personService,
		private ClockInterface       $clock,
	) {}

	/**
	 * Резолвит ID существующей группы (только чтение).
	 */
	public function resolveGroupId( string $name, ImportContextDTO $ctx ): ?int {
		$existing = $this->groups->findByNameSubjectPeriod( $name, $ctx->subjectKey, $ctx->periodId );

		return $existing ? (int) $existing->id : null;
	}

	/**
	 * Резолвит ID существующего person по doc/email/ФИО (только чтение).
	 */
	public function resolvePersonId( PersonInputDTO $input ): ?int {
		return $this->personResolver->resolve( $input );
	}

	/**
	 * Создаёт новую группу.
	 */
	public function createGroup( string $name, ImportContextDTO $ctx ): int {
		$now = $this->clock->now( 'mysql', true );

		return $this->groups->create( array(
			'name'               => $name,
			'subject_key'        => $ctx->subjectKey,
			'academic_period_id' => $ctx->periodId,
			'teacher_id'         => null,
			'meetings'           => null,
			'created_at'         => $now,
			'updated_at'         => $now,
		) );
	}

	/**
	 * Создаёт новый person (с person_documents).
	 */
	public function createPerson( PersonInputDTO $input ): int {
		return $this->personService->createOrFindBy( $input );
	}
}
