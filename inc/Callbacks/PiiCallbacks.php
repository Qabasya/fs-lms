<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\PersonInputDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Enums\PiiField;
use Inc\Enums\UserRole;
use Inc\Enums\WeekDay;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\ArchiveRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Services\EmailService;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\Person\PersonReader;
use Inc\Services\Person\PersonService;
use Inc\Services\Person\PiiMaskingService;
use Inc\Services\PiiCryptoService;
use Inc\Services\RateLimitService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class PiiCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PersonReader             $personReader,
		private readonly PersonService            $personService,
		private readonly PersonRepository         $personRepository,
		private readonly ArchiveRepository        $archiveRepository,
		private readonly RateLimitService         $rateLimitService,
		private readonly EmailService             $emailService,
		private readonly AuditService             $auditService,
		private readonly EnrollmentRepository     $enrollmentRepository,
		private readonly GroupsRepository         $groupRepository,
		private readonly SubjectRepository        $subjectRepository,
		private readonly PiiCryptoService         $crypto,
		private readonly PiiMaskingService        $maskingService,
		private readonly PasswordGeneratorService $passwordGenerator,
	) {
		parent::__construct();
	}

	public function ajaxRevealPiiField(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( 'person_id' );
		$field    = $this->sanitizeText( 'field' );
		$reason   = $this->sanitizeText( 'reason' );

		try {
			$value = $this->personReader->readField( $personId, $field, $reason );
			$this->success( array( 'value' => $value ) );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxRequestPiiDeletion(): void {
		$this->authorize( Nonce::RequestPiiDeletion, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );

		$this->personService->softDelete( $personId, get_current_user_id() );

		$this->success();
	}

	public function ajaxAddRepresentative(): void {
		$this->authorize( Nonce::AddRepresentative, Capability::ManagePersons );

		$studentPersonId = $this->sanitizeInt( 'student_person_id' );

		$guardianPersonId = $this->personService->createOrFindBy( new PersonInputDTO(
			fullName:  $this->requireText( 'full_name' ),
			docNumber: $this->requireText( 'doc_number' ),
			role:      'parent',
			inn:       $this->sanitizeText( 'inn' ),
			address:   $this->sanitizeText( 'address' ),
			phone:     $this->sanitizeText( 'phone' ),
			email:     $this->sanitizeText( 'email' ) ?: null,
		) );

		$archiveRecord = $this->archiveRepository->findActiveByStudent( $studentPersonId );

		if ( $archiveRecord !== null ) {
			$this->archiveRepository->update( $archiveRecord->id, array( 'parent_person_id' => $guardianPersonId ) );
		}

		$this->success();
	}

	public function ajaxReplaceRepresentative(): void {
		$this->authorize( Nonce::ReplaceRepresentative, Capability::ManagePersons );

		$archiveId = $this->sanitizeInt( 'archive_id' );

		$newGuardianId = $this->personService->createOrFindBy( new PersonInputDTO(
			fullName:  $this->requireText( 'full_name' ),
			docNumber: $this->requireText( 'doc_number' ),
			role:      'parent',
			inn:       $this->sanitizeText( 'inn' ),
			email:     $this->sanitizeText( 'email' ) ?: null,
		) );

		$this->archiveRepository->update( $archiveId, array( 'parent_person_id' => $newGuardianId ) );

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
			'phone'      => $this->sanitizeText( 'phone' ),
			'email'      => $this->sanitizeText( 'email' ),
			'birth_date' => $this->sanitizeText( 'birth_date' ),
			'doc_number' => $this->sanitizeText( 'doc_number' ),
			'inn'        => $this->sanitizeText( 'inn' ),
			'address'    => $this->sanitizeText( 'address' ),
		) );

		if ( $lastName || $firstName || $middleName ) {
			$personChanges['full_name'] = implode( ' ', array_filter( [ $lastName, $firstName, $middleName ] ) );
		}

		if ( ! empty( $personChanges ) ) {
			$this->personService->update( $personId, $personChanges, get_current_user_id() );
		}

		if ( $person->wpUserId ) {
			$userData = [ 'ID' => $person->wpUserId ];

			$newLogin = $this->sanitizeText( 'login' );
			if ( $newLogin ) {
				$userData['user_login'] = $newLogin;
			}

			if ( isset( $personChanges['email'] ) ) {
				$userData['user_email'] = $personChanges['email'];
			}

			if ( isset( $personChanges['full_name'] ) ) {
				$userData['display_name'] = $personChanges['full_name'];
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
				} catch ( \RuntimeException ) {}
			}
		}

		$newSchool = $this->sanitizeText( 'school' );
		if ( $newSchool ) {
			foreach ( $this->enrollmentRepository->findByStudent( $personId ) as $enr ) {
				if ( empty( $enr->snapshotEnc ) ) {
					continue;
				}
				try {
					$snapshot = json_decode( $this->crypto->decrypt( $enr->snapshotEnc ), true ) ?? array();
					$snapshot['student']['school'] = $newSchool;
					$this->enrollmentRepository->update( $enr->id, array(
						'snapshot_enc' => $this->crypto->encrypt( (string) wp_json_encode( $snapshot ) ),
						'updated_at'   => current_time( 'mysql', true ),
					) );
				} catch ( \Throwable ) {}
			}
		}

		$childDocNumber = $this->sanitizeText( 'child_doc_number' );
		$childInn       = $this->sanitizeText( 'child_inn' );
		$childBirthDate = $this->sanitizeText( 'child_birth_date' );

		if ( $childDocNumber || $childInn || $childBirthDate ) {
			$dependents = $this->archiveRepository->findActiveByParent( $personId );
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

	public function ajaxGetPersonData(): void {
		$this->authorize( Nonce::Manager, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );
		$person   = $this->personRepository->find( $personId );

		if ( null === $person ) {
			$this->error( 'Person не найден.' );
		}

		$wpUser    = $person->wpUserId ? get_userdata( $person->wpUserId ) : null;
		$roles     = $wpUser ? (array) $wpUser->roles : array();
		$isStudent = in_array( UserRole::FSStudent->value, $roles, true );
		$isParent  = in_array( UserRole::FSParent->value, $roles, true );
		$type      = $isStudent ? 'student' : ( $isParent ? 'parent' : 'unknown' );

		$representatives = array();
		$dependents      = array();

		if ( $isStudent ) {
			$archiveRecord = $this->archiveRepository->findActiveByStudent( $personId );
			if ( $archiveRecord !== null ) {
				$gPerson = $this->personRepository->find( $archiveRecord->parentPersonId );
				$gUser   = $gPerson?->wpUserId ? get_userdata( $gPerson->wpUserId ) : null;
				$representatives[] = array(
					'archive_id'         => $archiveRecord->id,
					'guardian_person_id' => $archiveRecord->parentPersonId,
					'name'               => $gUser ? $gUser->display_name : ( $gPerson?->fullName ?: "Person #{$archiveRecord->parentPersonId}" ),
					'type_label'         => 'Родитель',
					'since'              => substr( $archiveRecord->enrolledAt, 0, 10 ),
					'person_url'         => admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $archiveRecord->parentPersonId ),
				);
			}
		}

		if ( $isParent ) {
			foreach ( $this->archiveRepository->findActiveByParent( $personId ) as $archiveRecord ) {
				$sPerson = $this->personRepository->find( $archiveRecord->studentPersonId );
				$sUser   = $sPerson?->wpUserId ? get_userdata( $sPerson->wpUserId ) : null;
				$dependents[] = array(
					'archive_id'        => $archiveRecord->id,
					'student_person_id' => $archiveRecord->studentPersonId,
					'name'              => $sUser ? $sUser->display_name : ( $sPerson?->fullName ?: "Person #{$archiveRecord->studentPersonId}" ),
					'type_label'        => 'Ученик',
					'since'             => substr( $archiveRecord->enrolledAt, 0, 10 ),
					'person_url'        => admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $archiveRecord->studentPersonId ),
				);
			}
		}

		$personIds    = $isStudent ? array( $personId ) : array_column( $dependents, 'student_person_id' );
		$enrollments  = array();
		$nameMap      = array_column( $dependents, 'name', 'student_person_id' );
		$docIssuedStr = '';

		foreach ( $personIds as $pid ) {
			foreach ( $this->enrollmentRepository->findByStudent( $pid ) as $enr ) {
				$group    = $enr->groupId ? $this->groupRepository->findById( $enr->groupId ) : null;
				$snapshot = array();
				if ( ! empty( $enr->snapshotEnc ) ) {
					try {
						$snapshot = json_decode( $this->crypto->decrypt( $enr->snapshotEnc ), true ) ?? array();
					} catch ( \Throwable ) {}
				}
				$sd = $snapshot['student']  ?? array();
				$gd = $snapshot['guardian'] ?? array();

				if ( $isParent && $docIssuedStr === '' ) {
					$by   = trim( (string) ( $gd['doc_issued_by']   ?? '' ) );
					$date = trim( (string) ( $gd['doc_issued_date'] ?? '' ) );
					if ( $by !== '' || $date !== '' ) {
						$docIssuedStr = $by . ( $date !== '' ? ' ' . $date : '' );
					}
				}

				$enrollments[] = array(
					'student_name'        => $isParent ? ( $nameMap[ $pid ] ?? "#{$pid}" ) : null,
					'group_id'            => $enr->groupId,
					'group_title'         => $group?->group_name ?? '—',
					'schedule'            => $this->formatSchedule( $group ),
					'status_label'        => $enr->status->label(),
					'status_value'        => $enr->status->value,
					'enrolled_at'         => substr( $enr->enrolledAt, 0, 10 ),
					'terminated_at'       => $enr->terminatedAt ? substr( $enr->terminatedAt, 0, 10 ) : null,
					'contract_no'         => $snapshot['contract_no']   ?? '',
					'last_name'           => $sd['last_name']   ?? '',
					'first_name'          => $sd['first_name']  ?? '',
					'middle_name'         => $sd['middle_name'] ?? '',
					'student_phone'       => $sd['phone']       ?? '',
					'guardian_phone'      => $gd['phone']       ?? '',
					'school'              => $sd['school']      ?? '',
					'grade'               => isset( $sd['grade'] ) ? (string) $sd['grade'] : '',
					'birth_date'          => $sd['birth_date']  ?? '',
					'child_doc_number'    => $sd['doc_number']  ?? '',
					'child_inn'           => $sd['inn']         ?? '',
					'child_birth_date'    => $sd['birth_date']  ?? '',
					'guardian_birth_date' => $gd['birth_date']  ?? '',
				);
			}
		}

		$credentials = $person->wpUserId ? $this->passwordGenerator->getCredentials( $person->wpUserId ) : null;

		$maskedPii = $this->getMaskedPersonPii( $personId );
		if ( $isParent ) {
			$maskedPii['doc_issued'] = $docIssuedStr !== '' ? '•••••• от ••.••.••••' : '';
		}

		$this->success( array(
			'type'            => $type,
			'wp_user_id'      => $person->wpUserId ?? 0,
			'display_name'    => $wpUser ? $wpUser->display_name : $person->fullName,
			'login'           => $wpUser ? $wpUser->user_login : '',
			'email'           => $wpUser ? $wpUser->user_email : '',
			'password'        => $credentials['password'] ?? '',
			'masked_pii'      => $maskedPii,
			'representatives' => $representatives,
			'dependents'      => $dependents,
			'enrollments'     => $enrollments,
		) );
	}

	public function ajaxRevealAllPersonPii(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( 'person_id' );
		$reason   = $this->sanitizeText( 'reason' ) ?: 'admin_full_reveal';

		try {
			$dto = $this->personReader->readForDisplay(
				$personId,
				array( 'doc_number', 'inn', 'address', 'phone' ),
				$reason
			);

			$payload = array(
				'doc_number' => $dto->pass,
				'inn'        => $dto->inn,
				'address'    => $dto->address,
				'phone'      => $dto->phone,
			);

			$docIssued = $this->getDocIssuedFromSnapshot( $personId );
			if ( $docIssued !== '' ) {
				$payload['doc_issued'] = $docIssued;
			}

			$this->success( $payload );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	private function getDocIssuedFromSnapshot( int $personId ): string {
		foreach ( $this->archiveRepository->findActiveByParent( $personId ) as $archiveRecord ) {
			foreach ( $this->enrollmentRepository->findByStudent( $archiveRecord->studentPersonId ) as $enr ) {
				if ( empty( $enr->snapshotEnc ) ) {
					continue;
				}
				try {
					$snapshot = json_decode( $this->crypto->decrypt( $enr->snapshotEnc ), true ) ?? array();
				} catch ( \Throwable ) {
					continue;
				}
				$gd   = $snapshot['guardian'] ?? array();
				$by   = trim( (string) ( $gd['doc_issued_by']   ?? '' ) );
				$date = trim( (string) ( $gd['doc_issued_date'] ?? '' ) );
				if ( $by !== '' || $date !== '' ) {
					return $by . ( $date !== '' ? ' от ' . $date : '' );
				}
			}
		}
		return '';
	}

	private function getMaskedPersonPii( int $personId ): array {
		try {
			$dto = $this->personReader->readForDisplay(
				$personId,
				array( 'doc_number', 'inn', 'address', 'phone' ),
				'admin_masked_view'
			);
			return array(
				'doc_number' => $this->maskingService->mask( $dto->pass,    PiiField::Pass )
					?: $this->maskingService->placeholder( PiiField::Pass ),
				'inn'        => $this->maskingService->mask( $dto->inn,     PiiField::Inn )
					?: $this->maskingService->placeholder( PiiField::Inn ),
				'address'    => $this->maskingService->mask( $dto->address, PiiField::Address ),
			);
		} catch ( \Throwable ) {
			return array(
				'doc_number' => $this->maskingService->placeholder( PiiField::Pass ),
				'inn'        => $this->maskingService->placeholder( PiiField::Inn ),
				'address'    => '',
			);
		}
	}

	private function formatSchedule( mixed $group ): string {
		if ( null === $group ) {
			return '';
		}

		$raw      = $group->schedule ?? null;
		$schedule = is_string( $raw ) ? ( json_decode( $raw, true ) ?: array() ) : ( is_array( $raw ) ? $raw : array() );

		if ( empty( $schedule ) ) {
			return '';
		}

		return WeekDay::formatSchedule( $schedule );
	}

	public function renderPersonsPage(): void {
		if ( ! current_user_can( Capability::ManagePersons->value ) ) {
			wp_die( 'Доступ запрещён.' );
		}

		$template = $this->path( 'templates/admin/enrollment/persons-list.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Люди</h1><p>Шаблон не найден.</p></div>';
		}
	}

	public function renderPersonDetailPage(): void {
		if ( ! current_user_can( Capability::ManagePersons->value ) ) {
			wp_die( 'Доступ запрещён.' );
		}

		$personId = (int) ( $_GET['id'] ?? 0 );
		$person   = $this->personRepository->find( $personId );

		if ( null === $person ) {
			wp_die( 'Запись не найдена.' );
		}

		$decrypted = current_user_can( Capability::ViewPII->value )
			? $this->personReader->readForDisplay( $personId, array( 'full_name', 'doc_number', 'inn', 'address', 'phone' ), 'admin_view' )
			: null;

		$template = $this->path( 'templates/admin/enrollment/person-detail.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Person #' . esc_html( (string) $personId ) . '</h1><p>Шаблон не найден.</p></div>';
		}
	}
}
