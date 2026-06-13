<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Person\PersonService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class RepresentativeCallbacks
 *
 * AJAX-коллбеки для управления законными представителями (родителями) студентов.
 *
 * @package Inc\Callbacks\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Добавление представителя** — привязка родителя к студенту.
 * 2. **Замена представителя** — смена родителя у записи студента.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику PersonService (поиск/создание лица) и StudentRecordRepository
 * (обновление записи студента). Требует права Capability::ManagePersons.
 */
class RepresentativeCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), success()
	use Sanitizer;   // Трейт с методами sanitizeInt(), sanitizeText(), requireText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param PersonService           $personService           Сервис управления лицами
	 * @param StudentRecordRepository $studentRecordRepository Репозиторий записей студентов
	 */
	public function __construct(
		private readonly PersonService           $personService,
		private readonly StudentRecordRepository $studentRecordRepository,
	) {
		parent::__construct();
	}

	/**
	 * Добавляет законного представителя (родителя) к студенту.
	 *
	 * @return void
	 */
	public function ajaxAddRepresentative(): void {
		$this->authorize( Nonce::AddRepresentative, Capability::ManagePersons );

		$studentPersonId = $this->sanitizeInt( 'student_person_id' );

		// Создание или поиск существующего родителя по уникальным полям
		$guardianPersonId = $this->personService->createOrFindBy( new PersonInputDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			docNumber:  $this->requireText( 'doc_number' ),
			isStudent:  false,                    // Это родитель, не студент
			middleName: $this->sanitizeText( 'middle_name' ),
			inn:        $this->sanitizeText( 'inn' ),
			address:    $this->sanitizeText( 'address' ),
			phone:      $this->sanitizeText( 'phone' ),
			email:      $this->sanitizeText( 'email' ) ?: null,
		) );

		// Поиск активной записи студента (первой)
		$record = $this->studentRecordRepository->findActiveByStudentFirst( $studentPersonId );

		if ( $record !== null ) {
			// Обновление parent_person_id в записи студента
			$this->studentRecordRepository->update( $record->id, array( 'parent_person_id' => $guardianPersonId ) );
		}

		$this->success();
	}

	/**
	 * Заменяет законного представителя у записи студента.
	 *
	 * @return void
	 */
	public function ajaxReplaceRepresentative(): void {
		$this->authorize( Nonce::ReplaceRepresentative, Capability::ManagePersons );

		$recordId = $this->sanitizeInt( 'archive_id' );

		// Создание или поиск нового родителя
		$newGuardianId = $this->personService->createOrFindBy( new PersonInputDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			docNumber:  $this->requireText( 'doc_number' ),
			isStudent:  false,
			middleName: $this->sanitizeText( 'middle_name' ),
			inn:        $this->sanitizeText( 'inn' ),
			email:      $this->sanitizeText( 'email' ) ?: null,
		) );

		// Замена родителя в записи студента
		$this->studentRecordRepository->update( $recordId, array( 'parent_person_id' => $newGuardianId ) );

		$this->success();
	}
}