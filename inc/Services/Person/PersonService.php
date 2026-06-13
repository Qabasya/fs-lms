<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\DTO\Log\Events\PersonDataChangedEvent;
use Inc\DTO\Person\PersonInputDTO;
use Inc\DTO\Person\PersonRecordInputDTO;
use Inc\Enums\LogEvent;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Security\PiiCryptoService;
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
		private PersonRepository            $personRepository,
		private PersonDocumentsRepository   $personDocumentsRepository,
		private PiiCryptoService            $crypto,
		private ClockInterface              $clock,
		private LogEventDispatcherInterface $logEvents,
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
		$personId = $this->personRepository->create( new PersonRecordInputDTO(
			lastName:   $input->lastName,
			firstName:  $input->firstName,
			isStudent:  $input->isStudent ? 1 : 0,
			createdAt:  $now,
			updatedAt:  $now,
			middleName: $input->middleName !== '' ? $input->middleName : null,
			birthDate:  $input->birthDate  !== '' ? $input->birthDate  : null,
			school:     $input->school     !== '' ? $input->school     : null,
			grade:      $input->grade      !== '' ? $input->grade      : null,
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
		$existingPerson = $this->personRepository->find( $personId );
		if ( null === $existingPerson ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$existingDocs  = $this->personDocumentsRepository->findByPersonId( $personId );
		$personData    = array();
		$docData       = array();
		$changedFields = array();
		$fieldChanges  = array();

		foreach ( array( 'last_name', 'first_name', 'middle_name', 'school', 'grade' ) as $nameField ) {
			if ( array_key_exists( $nameField, $changes ) ) {
				$oldVal                   = (string) ( $existingPerson->$nameField ?? '' );
				$newVal                   = (string) $changes[ $nameField ];
				$personData[ $nameField ] = $newVal;
				$changedFields[]          = $nameField;
				if ( $oldVal !== $newVal ) {
					$fieldChanges[] = array( 'field' => $nameField, 'old' => $oldVal, 'new' => $newVal );
				}
			}
		}

		if ( array_key_exists( 'birth_date', $changes ) ) {
			$oldVal                    = (string) ( $existingPerson->birthDate ?? '' );
			$newVal                    = (string) $changes['birth_date'];
			$personData['birth_date']  = $newVal;
			$changedFields[]           = 'birth_date';
			if ( $oldVal !== $newVal ) {
				$fieldChanges[] = array( 'field' => 'birth_date', 'old' => $oldVal, 'new' => $newVal );
			}
		}

		$encFieldMap = array(
			'email'         => 'emailEnc',
			'phone'         => 'phoneEnc',
			'doc_number'    => 'docNumberEnc',
			'inn'           => 'innEnc',
			'address'       => 'addressEnc',
			'doc_issued_by' => 'docIssuedByEnc',
		);

		foreach ( self::ENCRYPTED_DOC_FIELDS as $rawKey => $cols ) {
			if ( ! array_key_exists( $rawKey, $changes ) ) {
				continue;
			}

			$newVal                  = (string) $changes[ $rawKey ];
			$docData[ $cols['enc'] ] = $this->crypto->encrypt( $newVal );
			$changedFields[]         = $rawKey;

			if ( null !== $cols['hash'] ) {
				$docData[ $cols['hash'] ] = $this->crypto->hash( $newVal );
			}

			$encProp = $encFieldMap[ $rawKey ] ?? null;
			$oldEnc  = $encProp && $existingDocs ? $existingDocs->$encProp : null;
			$oldVal  = $oldEnc ? $this->crypto->decrypt( $oldEnc ) : '';
			if ( $oldVal !== $newVal ) {
				$fieldChanges[] = array( 'field' => $rawKey, 'old' => $oldVal, 'new' => $newVal );
			}
		}

		if ( array_key_exists( 'doc_type', $changes ) ) {
			$oldVal              = (string) ( $existingDocs?->docType ?? '' );
			$newVal              = (string) $changes['doc_type'];
			$docData['doc_type'] = $newVal;
			$changedFields[]     = 'doc_type';
			if ( $oldVal !== $newVal ) {
				$fieldChanges[] = array( 'field' => 'doc_type', 'old' => $oldVal, 'new' => $newVal );
			}
		}

		if ( array_key_exists( 'doc_issued_date', $changes ) ) {
			$oldVal                      = (string) ( $existingDocs?->docIssuedDate ?? '' );
			$newVal                      = (string) $changes['doc_issued_date'];
			$docData['doc_issued_date']  = $newVal;
			$changedFields[]             = 'doc_issued_date';
			if ( $oldVal !== $newVal ) {
				$fieldChanges[] = array( 'field' => 'doc_issued_date', 'old' => $oldVal, 'new' => $newVal );
			}
		}

		if ( ! empty( $personData ) ) {
			$personData['updated_at'] = $this->clock->now( 'mysql', true );
			$this->personRepository->update( $personId, $personData );
		}

		if ( ! empty( $docData ) ) {
			if ( null === $existingDocs ) {
				$docData['person_id'] = $personId;
				$this->personDocumentsRepository->create( $docData );
			} else {
				$this->personDocumentsRepository->update( $personId, $docData );
			}
		}

		if ( empty( $personData ) && empty( $docData ) ) {
			return;
		}

		foreach ( $fieldChanges as $fc ) {
			$this->logEvents->dispatch(
				LogEvent::PersonDataChanged,
				new PersonDataChangedEvent( get_current_user_id(), $personId, $fc['field'], $fc['old'], $fc['new'] )
			);
		}
	}

	public function softDelete( int $personId, int $actorId ): void {
		if ( null === $this->personRepository->find( $personId ) ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$result = $this->personRepository->softDelete( $personId );

		if ( ! $result ) {
			throw new RuntimeException( "Не удалось выполнить soft delete для person ID {$personId}." );
		}

		$this->logEvents->dispatch(
			LogEvent::PersonSoftDeleted,
			new EntityHardDeletedEvent( $actorId, 'person', $personId ),
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
