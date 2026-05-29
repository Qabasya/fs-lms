<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\AuditAction;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Repositories\WPDBRepositories\RelationshipRepository;
use InvalidArgumentException;

readonly class PiiExportService {

	public function __construct(
		private PersonRepository      $personRepository,
		private EnrollmentRepository  $enrollmentRepository,
		private RelationshipRepository $relationshipRepository,
		private ConsentRepository     $consentRepository,
		private PiiCryptoService      $crypto,
		private PiiAccessLogRepository $piiAccessLogRepository,
		private AuditService          $auditService,
	) {}

	public function buildExport( int $personId, int $actorId ): string {
		$person = $this->personRepository->find( $personId );

		if ( null === $person ) {
			throw new InvalidArgumentException( "Person с ID {$personId} не найден." );
		}

		$decrypted = array(
			'full_name'  => null !== $person->fullNameEnc ? $this->crypto->decrypt( $person->fullNameEnc ) : null,
			'doc_number' => null !== $person->docNumberEnc ? $this->crypto->decrypt( $person->docNumberEnc ) : null,
			'inn'        => null !== $person->innEnc ? $this->crypto->decrypt( $person->innEnc ) : null,
			'snils'      => null !== $person->snilsEnc ? $this->crypto->decrypt( $person->snilsEnc ) : null,
			'address'    => null !== $person->addressEnc ? $this->crypto->decrypt( $person->addressEnc ) : null,
			'phone'      => null !== $person->phoneEnc ? $this->crypto->decrypt( $person->phoneEnc ) : null,
			'email'      => $person->email,
		);

		$this->piiAccessLogRepository->create( array(
			'actor_user_id'   => $actorId,
			'actor_role'      => 'exporter',
			'person_id'       => $personId,
			'fields_accessed' => 'full_name,doc_number,inn,snils,address,phone',
			'access_reason'   => 'gdpr_export',
			'actor_ip'        => '',
			'created_at'      => current_time( 'mysql', true ),
		) );

		$enrollments   = $this->enrollmentRepository->findByStudent( $personId );
		$relAsStudent  = $this->relationshipRepository->findActiveByStudent( $personId );
		$relAsGuardian = $this->relationshipRepository->findActiveByGuardian( $personId );
		$consents      = $this->consentRepository->findByPerson( $personId );

		$this->auditService->record( AuditAction::PiiExported->value, 'person', $personId );

		$enrollmentsData = array_map( fn( $e ) => $e->toArray(), $enrollments );
		$relationshipsData = array_map( fn( $r ) => $r->toArray(), array_merge( $relAsStudent, $relAsGuardian ) );
		$consentsData = array_map( fn( $c ) => $c->toArray(), $consents );

		return (string) wp_json_encode( array(
			'exported_at'   => current_time( 'c' ),
			'person'        => $decrypted,
			'enrollments'   => $enrollmentsData,
			'relationships' => $relationshipsData,
			'consents'      => $consentsData,
		) );
	}

	public function createDownloadLink( string $payload ): string {
		$token     = wp_generate_password( 32, false );
		$uploadDir = wp_upload_dir();
		$dir       = $uploadDir['basedir'] . '/lms-exports/';

		wp_mkdir_p( $dir );

		$filename = $dir . $token . '.json';
		file_put_contents( $filename, $payload );

		set_transient( 'fs_lms_export_' . $token, $filename, HOUR_IN_SECONDS );

		return home_url( '/lms/pii-export/' . $token );
	}
}