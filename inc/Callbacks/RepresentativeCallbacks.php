<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\PersonInputDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Person\PersonService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class RepresentativeCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PersonService           $personService,
		private readonly StudentRecordRepository $studentRecordRepository,
	) {
		parent::__construct();
	}

	public function ajaxAddRepresentative(): void {
		$this->authorize( Nonce::AddRepresentative, Capability::ManagePersons );

		$studentPersonId = $this->sanitizeInt( 'student_person_id' );

		$guardianPersonId = $this->personService->createOrFindBy( new PersonInputDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			docNumber:  $this->requireText( 'doc_number' ),
			isStudent:  false,
			middleName: $this->sanitizeText( 'middle_name' ),
			inn:        $this->sanitizeText( 'inn' ),
			address:    $this->sanitizeText( 'address' ),
			phone:      $this->sanitizeText( 'phone' ),
			email:      $this->sanitizeText( 'email' ) ?: null,
		) );

		$record = $this->studentRecordRepository->findActiveByStudentFirst( $studentPersonId );

		if ( $record !== null ) {
			$this->studentRecordRepository->update( $record->id, array( 'parent_person_id' => $guardianPersonId ) );
		}

		$this->success();
	}

	public function ajaxReplaceRepresentative(): void {
		$this->authorize( Nonce::ReplaceRepresentative, Capability::ManagePersons );

		$recordId = $this->sanitizeInt( 'archive_id' );

		$newGuardianId = $this->personService->createOrFindBy( new PersonInputDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			docNumber:  $this->requireText( 'doc_number' ),
			isStudent:  false,
			middleName: $this->sanitizeText( 'middle_name' ),
			inn:        $this->sanitizeText( 'inn' ),
			email:      $this->sanitizeText( 'email' ) ?: null,
		) );

		$this->studentRecordRepository->update( $recordId, array( 'parent_person_id' => $newGuardianId ) );

		$this->success();
	}
}
