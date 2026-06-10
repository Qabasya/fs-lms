<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\Person\PersonService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class PersonUpdateCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PersonService           $personService,
		private readonly PersonRepository        $personRepository,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly PasswordGeneratorService $passwordGenerator,
	) {
		parent::__construct();
	}

	public function ajaxRequestPiiDeletion(): void {
		$this->authorize( Nonce::RequestPiiDeletion, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );

		$this->personService->softDelete( $personId, get_current_user_id() );

		$this->success();
	}

	public function ajaxUpdatePerson(): void {
		$this->authorize( Nonce::UpdatePerson, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );
		$person   = $this->personRepository->find( $personId );

		if ( null === $person ) {
			$this->error( 'Person не найден.' );
		}

		$lastName   = $this->sanitizeText( 'last_name' );
		$firstName  = $this->sanitizeText( 'first_name' );
		$middleName = $this->sanitizeText( 'middle_name' );

		$personChanges = array_filter( array(
			'phone'           => $this->sanitizeText( 'phone' ),
			'email'           => $this->sanitizeText( 'email' ),
			'birth_date'      => $this->sanitizeText( 'birth_date' ),
			'doc_number'      => $this->sanitizeText( 'doc_number' ),
			'inn'             => $this->sanitizeText( 'inn' ),
			'address'         => $this->sanitizeText( 'address' ),
			'doc_issued_by'   => $this->sanitizeText( 'doc_issued_by' ),
			'doc_issued_date' => $this->sanitizeText( 'doc_issued_date' ),
		) );

		if ( $lastName ) { $personChanges['last_name']   = $lastName; }
		if ( $firstName ) { $personChanges['first_name'] = $firstName; }
		if ( $middleName ) { $personChanges['middle_name'] = $middleName; }

		if ( ! empty( $personChanges ) ) {
			$this->personService->update( $personId, $personChanges, get_current_user_id() );
		}

		if ( $person->wpUserId ) {
			$userData = array( 'ID' => $person->wpUserId );

			$newLogin = $this->sanitizeText( 'login' );
			if ( $newLogin ) {
				$userData['user_login'] = $newLogin;
			}

			if ( isset( $personChanges['email'] ) ) {
				$userData['user_email'] = $personChanges['email'];
			}

			if ( $lastName || $firstName ) {
				$userData['display_name'] = trim( $lastName . ' ' . $firstName . ' ' . $middleName );
				$userData['first_name']   = $firstName;
				$userData['last_name']    = $lastName;
			}

			if ( count( $userData ) > 1 ) {
				wp_update_user( $userData );
			}

			$newPassword = $this->sanitizeText( 'password' );
			if ( $newPassword ) {
				try {
					$this->passwordGenerator->setFromPlain( $person->wpUserId, $newPassword );
				} catch ( \RuntimeException ) {
					// Логирование ошибки
				}
			}
		}

		$childDocNumber = $this->sanitizeText( 'child_doc_number' );
		$childInn       = $this->sanitizeText( 'child_inn' );
		$childBirthDate = $this->sanitizeText( 'child_birth_date' );

		if ( $childDocNumber || $childInn || $childBirthDate ) {
			$dependents = $this->studentRecordRepository->findActiveByParent( $personId );
			if ( ! empty( $dependents ) ) {
				$childPersonId = $dependents[0]->studentPersonId;
				$childChanges  = array_filter( array(
					'doc_number' => $childDocNumber,
					'inn'        => $childInn,
					'birth_date' => $childBirthDate,
				) );
				if ( ! empty( $childChanges ) ) {
					$this->personService->update( $childPersonId, $childChanges, get_current_user_id() );
				}
			}
		}

		$this->success();
	}
}
