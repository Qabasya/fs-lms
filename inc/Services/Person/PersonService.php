<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\AuditAction;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Services\PiiCryptoService;
use RuntimeException;

readonly class PersonService {

	private const ENCRYPTED_DOC_FIELDS = array(
		'doc_number'    => array( 'enc' => 'doc_number_enc', 'hash' => 'doc_number_hash' ),
		'inn'           => array( 'enc' => 'inn_enc',        'hash' => 'inn_hash' ),
		'email'         => array( 'enc' => 'email_enc',      'hash' => 'email_hash' ),
		'phone'         => array( 'enc' => 'phone_enc',      'hash' => 'phone_hash' ),
		'address'       => array( 'enc' => 'address_enc',    'hash' => null ),
		'doc_issued_by' => array( 'enc' => 'doc_issued_by_enc', 'hash' => null ),
	);

	public function __construct(
		private PersonRepository          $personRepository,
		private PersonDocumentsRepository $personDocumentsRepository,
		private PiiCryptoService          $crypto,
		private AuditService              $auditService,
		private ClockInterface            $clock,
	) {}

	public function createOrFindBy( PersonInputDTO $input ): int {
		$docHash  = $this->crypto->hash( $input->docNumber );
		$existing = $this->personDocumentsRepository->findByDocNumberHash( $docHash );

		if ( null !== $existing ) {
			$person = $this->personRepository->findIncludingDeleted( $existing->personId );
			if ( $person !== null && $person->expelledAt !== null ) {
				$this->personRepository->update( $existing->personId, array( 'expelled_at' => null ) );
			}
			return $existing->personId;
		}

		$now      = $this->clock->now( 'mysql', true );
		$personId = $this->personRepository->create( array(
			'last_name'   => $input->lastName,
			'first_name'  => $input->firstName,
			'middle_name' => $input->middleName !== '' ? $input->middleName : null,
			'birth_date'  => $input->birthDate !== '' ? $input->birthDate : null,
			'is_student'  => $input->isStudent ? 1 : 0,
			'school'      => $input->school !== '' ? $input->school : null,
			'grade'       => $input->grade  !== '' ? $input->grade  : null,
			'created_at'  => $now,
			'updated_at'  => $now,
		) );

		if ( 0 === $personId ) {
			throw new RuntimeException( 'Не удалось создать запись person.' );
		}

		$this->personDocumentsRepository->create(
			$this->buildDocumentData( $personId, $input->toRawData() )
		);

		return $personId;
	}

	public function update( int $personId, array $changes, int $actorId ): void {
		if ( null === $this->personRepository->find( $personId ) ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$personData   = array();
		$docData      = array();
		$changedFields = array();

		foreach ( array( 'last_name', 'first_name', 'middle_name', 'school', 'grade' ) as $nameField ) {
			if ( array_key_exists( $nameField, $changes ) ) {
				$personData[ $nameField ] = (string) $changes[ $nameField ];
				$changedFields[]          = $nameField;
			}
		}

		if ( array_key_exists( 'birth_date', $changes ) ) {
			$personData['birth_date'] = (string) $changes['birth_date'];
			$changedFields[]         = 'birth_date';
		}

		foreach ( self::ENCRYPTED_DOC_FIELDS as $rawKey => $cols ) {
			if ( ! array_key_exists( $rawKey, $changes ) ) {
				continue;
			}

			$value                 = (string) $changes[ $rawKey ];
			$docData[ $cols['enc'] ] = $this->crypto->encrypt( $value );
			$changedFields[]       = $rawKey;

			if ( null !== $cols['hash'] ) {
				$docData[ $cols['hash'] ] = $this->crypto->hash( $value );
			}
		}

		if ( array_key_exists( 'doc_type', $changes ) ) {
			$docData['doc_type'] = (string) $changes['doc_type'];
			$changedFields[]     = 'doc_type';
		}

		if ( array_key_exists( 'doc_issued_date', $changes ) ) {
			$docData['doc_issued_date'] = (string) $changes['doc_issued_date'];
			$changedFields[]            = 'doc_issued_date';
		}

		if ( ! empty( $personData ) ) {
			$personData['updated_at'] = $this->clock->now( 'mysql', true );
			$this->personRepository->update( $personId, $personData );
		}

		if ( ! empty( $docData ) ) {
			if ( null === $this->personDocumentsRepository->findByPersonId( $personId ) ) {
				$docData['person_id'] = $personId;
				$this->personDocumentsRepository->create( $docData );
			} else {
				$this->personDocumentsRepository->update( $personId, $docData );
			}
		}

		if ( empty( $personData ) && empty( $docData ) ) {
			return;
		}

		$this->auditService->record(
			AuditAction::UpdatePerson->value,
			'person',
			$personId,
			array( 'changed_fields' => $changedFields ),
		);
	}

	public function softDelete( int $personId, int $actorId ): void {
		if ( null === $this->personRepository->find( $personId ) ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$result = $this->personRepository->softDelete( $personId );

		if ( ! $result ) {
			throw new RuntimeException( "Не удалось выполнить soft delete для person ID {$personId}." );
		}

		$this->auditService->record(
			AuditAction::PiiDeletionRequested->value,
			'person',
			$personId,
		);
	}

	public function anonymize( int $personId ): void {
		$this->personDocumentsRepository->anonymize( $personId );
	}

	public function findByDocNumberHash( string $hash ): ?int {
		$docs = $this->personDocumentsRepository->findByDocNumberHash( $hash );
		return $docs?->personId;
	}

	public function findByEmailHash( string $hash ): ?int {
		$docs = $this->personDocumentsRepository->findByEmailHash( $hash );
		return $docs?->personId;
	}

	private function buildDocumentData( int $personId, array $rawData ): array {
		$data = array( 'person_id' => $personId );

		foreach ( self::ENCRYPTED_DOC_FIELDS as $rawKey => $cols ) {
			if ( ! isset( $rawData[ $rawKey ] ) || '' === (string) $rawData[ $rawKey ] ) {
				continue;
			}

			$value                 = (string) $rawData[ $rawKey ];
			$data[ $cols['enc'] ] = $this->crypto->encrypt( $value );

			if ( null !== $cols['hash'] ) {
				$data[ $cols['hash'] ] = $this->crypto->hash( $value );
			}
		}

		if ( isset( $rawData['doc_type'] ) && '' !== (string) $rawData['doc_type'] ) {
			$data['doc_type'] = (string) $rawData['doc_type'];
		}

		if ( isset( $rawData['doc_issued_date'] ) && '' !== (string) $rawData['doc_issued_date'] ) {
			$data['doc_issued_date'] = (string) $rawData['doc_issued_date'];
		}

		return $data;
	}
}
