<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\DTO\PersonDecryptedDTO;
use Inc\DTO\PiiAccessLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;
use RuntimeException;

readonly class PersonReader {

	use RequestContextProvider;

	private const DOC_FIELD_MAP = array(
		'doc_number'    => 'docNumberEnc',
		'inn'           => 'innEnc',
		'address'       => 'addressEnc',
		'phone'         => 'phoneEnc',
		'email'         => 'emailEnc',
		'doc_issued_by' => 'docIssuedByEnc',
	);

	public function __construct(
		private PersonRepository          $personRepository,
		private PersonDocumentsRepository $personDocumentsRepository,
		private PiiCryptoService          $crypto,
		private PiiAccessLogRepository    $piiAccessLogRepository,
		private UserManager               $userManager,
		private ClockInterface            $clock,
	) {}

	public function readForDisplay( int $personId, array $fields, string $reason ): PersonDecryptedDTO {
		$person = $this->personRepository->find( $personId );

		if ( null === $person ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$docs = $this->personDocumentsRepository->findByPersonId( $personId );

		$decrypted = array_fill_keys( array_keys( self::DOC_FIELD_MAP ), '' );

		$fullName = '';
		foreach ( $fields as $field ) {
			if ( 'full_name' === $field ) {
				$fullName = $person->fullName;
				continue;
			}

			if ( ! isset( self::DOC_FIELD_MAP[ $field ] ) || null === $docs ) {
				continue;
			}

			$encProperty        = self::DOC_FIELD_MAP[ $field ];
			$decrypted[ $field ] = $this->decryptField( $docs->$encProperty );
		}

		$this->logAccess( $personId, $fields, $reason );

		return new PersonDecryptedDTO(
			personId: $personId,
			fullName: $fullName,
			pass:     $decrypted['doc_number'],
			inn:      $decrypted['inn'],
			address:  $decrypted['address'],
			phone:    $decrypted['phone'],
		);
	}

	public function readField( int $personId, string $field, string $reason ): string {
		$person = $this->personRepository->find( $personId );

		if ( null === $person ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		if ( 'full_name' === $field ) {
			$this->logAccess( $personId, array( $field ), $reason );
			return $person->fullName;
		}

		if ( ! isset( self::DOC_FIELD_MAP[ $field ] ) ) {
			throw new RuntimeException( "Неизвестное PII-поле: {$field}." );
		}

		$docs = $this->personDocumentsRepository->findByPersonId( $personId );
		$encProperty = self::DOC_FIELD_MAP[ $field ];
		$value = null !== $docs ? $this->decryptField( $docs->$encProperty ) : '';

		$this->logAccess( $personId, array( $field ), $reason );

		return $value;
	}

	private function decryptField( ?string $enc ): string {
		if ( null === $enc || '' === $enc ) {
			return '';
		}

		return $this->crypto->decrypt( $enc );
	}

	private function logAccess( int $personId, array $fields, string $reason ): void {
		$ctx  = $this->requestContext();
		$user = $ctx->actorUserId > 0 ? $this->userManager->find( $ctx->actorUserId ) : null;

		$this->piiAccessLogRepository->create( new PiiAccessLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:      ( null !== $user && ! empty( $user->roles ) )
				? (string) reset( $user->roles )
				: null,
			personId:       $personId,
			fieldsAccessed: implode( ',', $fields ),
			accessReason:   $reason,
			actorIp:        $ctx->ip,
			createdAt:      $this->clock->now( 'mysql', true ),
		) );
	}
}
