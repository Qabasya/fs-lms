<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use DomainException;
use Inc\DTO\Application\ApplicationRecordInputDTO;
use Inc\DTO\Enrollment\EnrollmentInputDTO;
use Inc\DTO\Enrollment\EnrollmentResultDTO;
use Inc\DTO\Enrollment\ParentAssignmentResultDTO;
use Inc\DTO\Enrollment\RemoveParentResultDTO;
use Inc\DTO\Enrollment\RestoreResultDTO;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\DTO\Person\UserInputDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\UserRole;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\OperationType;
use Inc\Services\ConsentService;
use Inc\Services\Email\EmailService;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Person\PersonService;
use Inc\Contracts\ClockInterface;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TransactionRunner;
use InvalidArgumentException;
use RuntimeException;

readonly class EnrollmentService {

	use TransactionRunner;
	use RequestContextProvider;

	public function __construct(
		private ApplicationRepository        $applicationRepository,
		private StudentRecordRepository      $studentRecordRepository,
		private PersonRepository             $personRepository,
		private PersonDocumentsRepository    $personDocumentsRepository,
		private PersonService                $personService,
		private GroupsRepository             $groupsRepository,
		private JoinCodeService              $joinCodeService,
		private ConsentService               $consentService,
		private UserManager                  $userManager,
		private PasswordGeneratorService     $passwordGenerator,
		private EmailService                 $emailService,
		private PiiCryptoService             $crypto,
		private ClockInterface               $clock,
		private LogEventDispatcherInterface  $logEvents,
	) {}

	public function enroll( EnrollmentInputDTO $input ): EnrollmentResultDTO {
		$app = $this->applicationRepository->find( $input->applicationId );

		if ( null === $app ) {
			throw new InvalidArgumentException( 'Заявка не найдена.' );
		}

		if ( ApplicationStatus::Enrolling !== $app->status ) {
			throw new DomainException( 'Заявка не в статусе enrolling.' );
		}

		$studentDto = StudentDataDTO::fromArray(
			json_decode( $this->crypto->decrypt( (string) $app->studentDataEnc ), true ) ?? array()
		);
		$parentDto  = ParentDataDTO::fromArray(
			json_decode( $this->crypto->decrypt( (string) $app->parentDataEnc ), true ) ?? array()
		);

		$existingStudent = null;
		if ( $app->studentPersonId !== null ) {
			$existingStudent = $this->personRepository->findIncludingDeleted( $app->studentPersonId );
			if ( $existingStudent !== null && $existingStudent->expelledAt !== null ) {
				$this->personRepository->update( $existingStudent->id, array( 'expelled_at' => null ) );
			}
		} else {
			$studentDocHash  = $this->crypto->hash( $studentDto->docNumber );
			$studentId       = $this->personService->findByDocNumberHash( $studentDocHash );
			$existingStudent = $studentId !== null ? $this->personRepository->find( $studentId ) : null;
		}

		$existingGuardian = null;
		if ( $app->parentPersonId !== null ) {
			$existingGuardian = $this->personRepository->find( $app->parentPersonId );
		} else {
			$guardianDocHash  = $this->crypto->hash( $parentDto->docNumber );
			$guardianId       = $this->personService->findByDocNumberHash( $guardianDocHash );
			$existingGuardian = $guardianId !== null ? $this->personRepository->find( $guardianId ) : null;
		}

		if ( null === $existingGuardian && null !== $this->userManager->findByEmail( $parentDto->email ) ) {
			throw new DomainException( 'Email родителя уже занят другим пользователем.' );
		}

		if ( null !== $existingStudent && $this->studentRecordRepository->existsActive( $existingStudent->id, $input->groupId ) ) {
			throw new DomainException( 'Ученик уже зачислен в эту группу.' );
		}

		$result = $this->inTransaction( function () use ( $app, $input, $studentDto, $parentDto, $existingStudent, $existingGuardian ): array {
			$now = $this->clock->now( 'mysql', true );

			$studentPersonId = $existingStudent !== null
				? $existingStudent->id
				: $this->personService->createOrFindBy( new PersonInputDTO(
					lastName:   $studentDto->lastName,
					firstName:  $studentDto->firstName,
					docNumber:  $studentDto->docNumber,
					isStudent:  true,
					middleName: $studentDto->middleName,
					docType:    $studentDto->docType,
					birthDate:  $studentDto->birthDate,
					inn:        $studentDto->inn,
					phone:      $studentDto->phone,
					school:     $studentDto->school,
					grade:      (string) $studentDto->grade,
					email:      $studentDto->email !== '' ? $studentDto->email : null,
				) );

			$guardianPersonId = $existingGuardian !== null
				? $existingGuardian->id
				: $this->personService->createOrFindBy( new PersonInputDTO(
					lastName:      $parentDto->lastName,
					firstName:     $parentDto->firstName,
					docNumber:     $parentDto->docNumber,
					isStudent:     false,
					middleName:    $parentDto->middleName,
					docType:       $parentDto->docType,
					birthDate:     $parentDto->birthDate,
					inn:           $parentDto->inn,
					address:       $parentDto->address,
					phone:         $parentDto->phone,
					docIssuedBy:   $parentDto->docIssuedBy,
					docIssuedDate: $parentDto->docIssuedDate,
					email:         $parentDto->email !== '' ? $parentDto->email : null,
				) );

			$recordId = $this->studentRecordRepository->create( new StudentRecordInputDTO(
				studentPersonId:   $studentPersonId,
				parentPersonId:    $guardianPersonId,
				status:            'active',
				enrolledAt:        $input->enrolledAt,
				createdAt:         $now,
				updatedAt:         $now,
				groupId:           $input->groupId ?: null,
				snapshotLastName:  $studentDto->lastName,
				snapshotFirstName: $studentDto->firstName,
				snapshotMiddleName: $studentDto->middleName ?: null,
				snapshotSchool:    $studentDto->school      ?: null,
				snapshotGrade:     (string) $studentDto->grade ?: null,
				contractNo:        $input->contractNo   ?: null,
				contractDate:      $input->contractDate  ?: null,
				orderNo:           $input->orderNo      ?: null,
				orderDate:         $input->orderDate     ?: null,
				enrolledByUserId:  get_current_user_id() ?: null,
			) );

			$this->consentService->bindToPersons( $app->id, array(
				'self'     => $studentPersonId,
				'guardian' => $guardianPersonId,
			) );

			return array( $recordId, $studentPersonId, $guardianPersonId );
		} );

		[ $recordId, $studentPersonId, $guardianPersonId ] = $result;

		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::EnrollStudent, $studentPersonId, $recordId, $input->groupId )
		);

		try {
			$studentPerson = $this->personRepository->find( $studentPersonId );
			if ( null !== $studentPerson && null !== $studentPerson->wpUserId ) {
				$studentUserId = $studentPerson->wpUserId;
				$studentLogin  = $this->userManager->find( $studentUserId )?->user_login ?? '';
				if ( $studentDto->loginPassword !== '' ) {
					$this->passwordGenerator->setFromPlain( $studentUserId, $studentDto->loginPassword );
					$studentPassword = $studentDto->loginPassword;
				} else {
					$studentPassword = $this->passwordGenerator->generateAndSet( $studentUserId );
				}
			} else {
				$studentEmail = $studentDto->email;
				$existingUser = $studentEmail !== '' ? $this->userManager->findByEmail( $studentEmail ) : null;
				if ( null !== $existingUser ) {
					$studentUserId = $existingUser->ID;
					$studentLogin  = $existingUser->user_login;
					if ( $studentDto->loginPassword !== '' ) {
						$this->passwordGenerator->setFromPlain( $studentUserId, $studentDto->loginPassword );
						$studentPassword = $studentDto->loginPassword;
					} else {
						$studentPassword = $this->passwordGenerator->generateAndSet( $studentUserId );
					}
				} else {
					$studentLogin    = $studentDto->username !== ''
						? $studentDto->username
						: ( $studentEmail !== '' ? $studentEmail : 'student_' . $studentPersonId );
					$studentPassword = $studentDto->loginPassword !== ''
						? $studentDto->loginPassword
						: $this->passwordGenerator->generatePlain();
					$studentUserId   = $this->userManager->create( new UserInputDTO(
						userLogin:   $studentLogin,
						userEmail:   $studentEmail,
						userPass:    $studentPassword,
						displayName: $studentDto->fullName(),
						firstName:   $studentDto->firstName,
						lastName:    $studentDto->lastName,
						role:        UserRole::FSStudent->value,
					) );
					$this->passwordGenerator->storeEncrypted( $studentUserId, $studentPassword );
					$this->logEvents->dispatch(
						LogEvent::UserCreated,
						new EntityChangedEvent( get_current_user_id(), OperationType::Create, EntityType::Student, $studentPersonId, $studentDto->fullName() )
					);
				}
				if ( null !== $studentPerson && null === $studentPerson->wpUserId ) {
					$this->personRepository->setWpUser( $studentPersonId, $studentUserId );
					$this->userManager->setPersonId( $studentUserId, $studentPersonId );
				}
			}

			$guardianPerson = $this->personRepository->find( $guardianPersonId );
			if ( null !== $guardianPerson && null !== $guardianPerson->wpUserId ) {
				$guardianUserId   = $guardianPerson->wpUserId;
				$guardianLogin    = $this->userManager->find( $guardianUserId )?->user_login ?? '';
				$guardianPassword = $this->passwordGenerator->generateAndSet( $guardianUserId );
			} else {
				$guardianEmail        = $parentDto->email;
				$existingGuardianUser = $this->userManager->findByEmail( $guardianEmail );
				if ( null !== $existingGuardianUser ) {
					$guardianUserId   = $existingGuardianUser->ID;
					$guardianLogin    = $existingGuardianUser->user_login;
					$guardianPassword = $this->passwordGenerator->generateAndSet( $guardianUserId );
				} else {
					$guardianLogin    = $guardianEmail;
					$guardianPassword = $this->passwordGenerator->generatePlain();
					$guardianUserId   = $this->userManager->create( new UserInputDTO(
						userLogin:   $guardianEmail,
						userEmail:   $guardianEmail,
						userPass:    $guardianPassword,
						displayName: $parentDto->fullName(),
						firstName:   $parentDto->firstName,
						lastName:    $parentDto->lastName,
						role:        UserRole::FSParent->value,
					) );
					$this->passwordGenerator->storeEncrypted( $guardianUserId, $guardianPassword );
					$this->logEvents->dispatch(
						LogEvent::UserCreated,
						new EntityChangedEvent( get_current_user_id(), OperationType::Create, EntityType::Parent, $guardianPersonId, $parentDto->fullName() )
					);
				}
				if ( null !== $guardianPerson && null === $guardianPerson->wpUserId ) {
					$this->personRepository->setWpUser( $guardianPersonId, $guardianUserId );
					$this->userManager->setPersonId( $guardianUserId, $guardianPersonId );
				}
			}

			$this->applicationRepository->forceDelete( $app->id );

			if ( $input->sendEmailAuto ) {
				$sharedVars = array(
					'student_full_name'  => $studentDto->fullName(),
					'parent_first_name'  => $parentDto->firstName,
					'parent_middle_name' => $parentDto->middleName,
				);
				$this->emailService->sendWelcomeWithCredentials( $studentUserId, $studentPassword, $sharedVars );
				$this->emailService->sendWelcomeWithCredentials( $guardianUserId, $guardianPassword, $sharedVars );
			}

			return new EnrollmentResultDTO(
				$recordId,
				$studentUserId,
				$guardianUserId,
				$studentLogin,
				$studentPassword,
				$guardianLogin,
				$guardianPassword,
				false
			);
		} catch ( \Throwable $e ) {
			// Транзакция прошла (student_record создан), WP-пользователи не созданы.
			// Помечаем заявку как converted — RecoveryService (cron) подберёт её и создаст аккаунты.
			try {
				$this->applicationRepository->markConverted( $app->id, $recordId );
			} catch ( \Throwable ) {}

			PluginLogger::warning( 'EnrollmentService', 'WP user creation failed after transaction', array( 'record_id' => $recordId, 'error' => $e->getMessage() ) );

			$this->logEvents->dispatch(
				LogEvent::EnrollmentFailed,
				new EnrollmentStatusEvent( get_current_user_id(), AuditAction::EnrollStudentFailed, $studentPersonId, $recordId, $input->groupId )
			);

			return new EnrollmentResultDTO( $recordId, 0, 0, null, null, null, null, true, $e->getMessage() );
		}
	}

	public function restoreFromArchive( int $recordId, bool $withParent = false ): RestoreResultDTO {
		$record = $this->studentRecordRepository->find( $recordId );

		if ( null === $record ) {
			throw new InvalidArgumentException( 'Запись не найдена.' );
		}

		$studentPerson = $this->personRepository->findIncludingDeleted( $record->studentPersonId );

		$sLastName   = $record->snapshotLastName  !== '' ? $record->snapshotLastName  : ( $studentPerson?->lastName  ?? '' );
		$sFirstName  = $record->snapshotFirstName !== '' ? $record->snapshotFirstName : ( $studentPerson?->firstName ?? '' );
		$sMiddleName = $record->snapshotMiddleName ?? $studentPerson?->middleName ?? '';

		$studentData = array(
			'last_name'   => $sLastName,
			'first_name'  => $sFirstName,
			'middle_name' => $sMiddleName,
			'full_name'   => trim( "{$sLastName} {$sFirstName} {$sMiddleName}" ),
			'birth_date'  => $studentPerson?->birthDate ?? '',
			'school'      => $record->snapshotSchool ?? '',
			'grade'       => $record->snapshotGrade  ?? '',
			'email'       => '',
		);

		$docs = $this->personDocumentsRepository->findByPersonId( $record->studentPersonId );
		if ( $docs ) {
			$studentData['doc_type'] = $docs->docType ?? '';
			foreach ( array(
				'email'      => $docs->emailEnc,
				'phone'      => $docs->phoneEnc,
				'doc_number' => $docs->docNumberEnc,
				'inn'        => $docs->innEnc,
			) as $key => $enc ) {
				if ( ! $enc ) { continue; }
				try {
					$studentData[ $key ] = $this->crypto->decrypt( $enc );
				} catch ( \Throwable ) {}
			}
		}

		$joinCode       = $this->joinCodeService->generate();
		$joinHash       = $this->joinCodeService->hash( $joinCode );
		$joinEnc        = $this->crypto->encrypt( $joinCode );
		$expiresAt      = gmdate( 'Y-m-d H:i:s', strtotime( '+48 hours' ) );
		$studentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $studentData ) );

		$now   = $this->clock->now( 'mysql', true );
		$appId = $this->applicationRepository->create( new ApplicationRecordInputDTO(
			status:            ApplicationStatus::PendingParent->value,
			joinCodeHash:      $joinHash,
			joinCodeEnc:       $joinEnc,
			joinCodeExpiresAt: $expiresAt,
			studentDataEnc:    $studentDataEnc,
			createdAt:         $now,
			updatedAt:         $now,
			studentPersonId:   $record->studentPersonId,
		) );

		if ( 0 === $appId ) {
			throw new RuntimeException( 'Не удалось создать заявку.' );
		}

		$this->logEvents->dispatch(
			LogEvent::StudentRestored,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::RestoreFromArchive, $record->studentPersonId, $recordId, $record->groupId ?? null )
		);

		if ( $withParent ) {
			if ( $record->parentPersonId <= 0 ) {
				throw new \InvalidArgumentException( 'У этой записи нет привязанного родителя.' );
			}

			$parentResult = $this->selectExistingParent( $appId, $record->parentPersonId );

			$this->applicationRepository->update( $appId, array(
				'status'     => ApplicationStatus::ReadyForReview->value,
				'updated_at' => $this->clock->now( 'mysql', true ),
			) );

			return new RestoreResultDTO(
				appId:      $appId,
				joinUrl:    $parentResult->joinUrl,
				parentName: $parentResult->parentName,
			);
		}

		return new RestoreResultDTO(
			appId:   $appId,
			joinUrl: home_url( '/lms/join/' . $joinCode ),
		);
	}

	public function selectExistingParent( int $applicationId, int $parentPersonId ): ParentAssignmentResultDTO {
		$app = $this->applicationRepository->find( $applicationId );

		if ( null === $app ) {
			throw new InvalidArgumentException( 'Заявка не найдена.' );
		}

		if ( ApplicationStatus::PendingParent !== $app->status ) {
			throw new DomainException( 'Заявка не в статусе pending_parent.' );
		}

		$parentPerson = $this->personRepository->findIncludingDeleted( $parentPersonId );

		if ( null === $parentPerson ) {
			throw new InvalidArgumentException( 'Родитель не найден.' );
		}

		if ( null !== $parentPerson->expelledAt ) {
			$this->personRepository->update( $parentPersonId, array( 'expelled_at' => null ) );
		}

		$docs = $this->personDocumentsRepository->findByPersonId( $parentPersonId );

		$parentData = array(
			'last_name'       => $parentPerson->lastName,
			'first_name'      => $parentPerson->firstName,
			'middle_name'     => $parentPerson->middleName ?? '',
			'full_name'       => $parentPerson->fullName(),
			'birth_date'      => $parentPerson->birthDate ?? '',
			'email'           => '',
			'phone'           => '',
			'doc_type'        => '',
			'doc_number'      => '',
			'doc_issued_by'   => '',
			'doc_issued_date' => '',
			'inn'             => '',
			'address'         => '',
		);

		if ( $docs !== null ) {
			if ( $docs->emailEnc )       { $parentData['email']         = $this->crypto->decrypt( $docs->emailEnc ); }
			if ( $docs->phoneEnc )       { $parentData['phone']         = $this->crypto->decrypt( $docs->phoneEnc ); }
			if ( $docs->docNumberEnc )   { $parentData['doc_number']    = $this->crypto->decrypt( $docs->docNumberEnc ); }
			if ( $docs->docIssuedByEnc ) { $parentData['doc_issued_by'] = $this->crypto->decrypt( $docs->docIssuedByEnc ); }
			if ( $docs->docIssuedDate )  { $parentData['doc_issued_date'] = $docs->docIssuedDate; }
			if ( $docs->innEnc )         { $parentData['inn']           = $this->crypto->decrypt( $docs->innEnc ); }
			if ( $docs->addressEnc )     { $parentData['address']       = $this->crypto->decrypt( $docs->addressEnc ); }
			$parentData['doc_type'] = $docs->docType ?? '';
		}

		// Если email не найден в документах — берём из WP-аккаунта родителя
		if ( '' === $parentData['email'] && $parentPerson->wpUserId ) {
			$wpUser = get_userdata( $parentPerson->wpUserId );
			if ( $wpUser ) {
				$parentData['email'] = $wpUser->user_email;
			}
		}

		$parentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $parentData ) );

		$newCode = $this->joinCodeService->generate();
		$newHash = $this->joinCodeService->hash( $newCode );
		$newEnc  = $this->crypto->encrypt( $newCode );

		$this->applicationRepository->update( $applicationId, array(
			'parent_person_id' => $parentPersonId,
			'parent_data_enc'  => $parentDataEnc,
			'join_code_hash'   => $newHash,
			'join_code_enc'    => $newEnc,
			'updated_at'       => $this->clock->now( 'mysql', true ),
		) );

		return new ParentAssignmentResultDTO(
			joinUrl:    home_url( '/lms/join/' . $newCode ),
			parentName: $parentPerson->fullName(),
		);
	}

	public function removeParentAssignment( int $applicationId ): RemoveParentResultDTO {
		$app = $this->applicationRepository->find( $applicationId );

		if ( null === $app ) {
			throw new InvalidArgumentException( 'Заявка не найдена.' );
		}

		if ( ApplicationStatus::PendingParent !== $app->status ) {
			throw new DomainException( 'Заявка не в статусе pending_parent.' );
		}

		if ( null === $app->parentPersonId ) {
			throw new DomainException( 'Родитель не назначен.' );
		}

		$newCode = $this->joinCodeService->generate();
		$newHash = $this->joinCodeService->hash( $newCode );
		$newEnc  = $this->crypto->encrypt( $newCode );

		$this->applicationRepository->update( $applicationId, array(
			'parent_person_id' => null,
			'parent_data_enc'  => null,
			'join_code_hash'   => $newHash,
			'join_code_enc'    => $newEnc,
			'updated_at'       => $this->clock->now( 'mysql', true ),
		) );

		return new RemoveParentResultDTO( joinUrl: home_url( '/lms/join/' . $newCode ) );
	}
}
