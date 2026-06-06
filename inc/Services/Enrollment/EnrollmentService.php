<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use DomainException;
use Inc\DTO\EnrollmentInputDTO;
use Inc\DTO\EnrollmentResultDTO;
use Inc\DTO\ParentDataDTO;
use Inc\DTO\PersonInputDTO;
use Inc\DTO\StudentDataDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\UserRole;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\ArchiveRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\AuditService;
use Inc\Services\ConsentService;
use Inc\Services\EmailService;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\Person\PersonService;
use Inc\Contracts\ClockInterface;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TransactionRunner;
use InvalidArgumentException;
use RuntimeException;

readonly class EnrollmentService {

	use TransactionRunner;
	use RequestContextProvider;

	public function __construct(
		private ApplicationRepository     $applicationRepository,
		private EnrollmentRepository      $enrollmentRepository,
		private PersonRepository          $personRepository,
		private PersonDocumentsRepository $personDocumentsRepository,
		private PersonService             $personService,
		private ArchiveRepository         $archiveRepository,
		private GroupsRepository          $groupsRepository,
		private JoinCodeService           $joinCodeService,
		private ConsentService            $consentService,
		private AuditService              $auditService,
		private UserManager               $userManager,
		private PasswordGeneratorService  $passwordGenerator,
		private EmailService              $emailService,
		private PiiCryptoService          $crypto,
		private ClockInterface            $clock,
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

		// Используем pre-linked person_id из заявки, либо ищем по хешу документа
		$existingStudent = null;
		if ( $app->studentPersonId !== null ) {
			$existingStudent = $this->personRepository->find( $app->studentPersonId );
		} else {
			$studentDocHash = $this->crypto->hash( $studentDto->docNumber );
			$studentId      = $this->personService->findByDocNumberHash( $studentDocHash );
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

		if ( null !== $existingStudent && $this->enrollmentRepository->existsActive( $existingStudent->id, $input->groupId ) ) {
			throw new DomainException( 'Ученик уже зачислен в эту группу.' );
		}

		$result = $this->inTransaction( function () use ( $app, $input, $studentDto, $parentDto, $existingStudent, $existingGuardian ): array {
			$studentPersonId = $existingStudent !== null
				? $existingStudent->id
				: $this->personService->createOrFindBy( new PersonInputDTO(
					fullName:  $studentDto->fullName(),
					docNumber: $studentDto->docNumber,
					role:      'student',
					docType:   $studentDto->docType,
					birthDate: $studentDto->birthDate,
					inn:       $studentDto->inn,
					email:     $studentDto->email !== '' ? $studentDto->email : null,
				) );

			$guardianPersonId = $existingGuardian !== null
				? $existingGuardian->id
				: $this->personService->createOrFindBy( new PersonInputDTO(
					fullName:  $parentDto->fullName(),
					docNumber: $parentDto->docNumber,
					role:      'parent',
					docType:   $parentDto->docType,
					birthDate: $parentDto->birthDate,
					inn:       $parentDto->inn,
					address:   $parentDto->address,
					phone:     $parentDto->phone,
					email:     $parentDto->email !== '' ? $parentDto->email : null,
				) );

			$snapshot    = array(
				'student'       => $studentDto->toArray(),
				'guardian'      => $parentDto->toArray(),
				'enrolled_at'   => $input->enrolledAt,
				'contract_no'   => $input->contractNo,
				'contract_date' => $input->contractDate,
				'order_no'      => $input->orderNo,
				'order_date'    => $input->orderDate,
			);
			$enrollmentId = $this->enrollmentRepository->create( array(
				'student_person_id'     => $studentPersonId,
				'group_id'              => $input->groupId ?: null,
				'enrolled_at'           => $input->enrolledAt,
				'status'                => 'active',
				'snapshot_enc'          => $this->crypto->encrypt( (string) wp_json_encode( $snapshot ) ),
				'source_application_id' => $app->id,
				'created_at'            => $this->clock->now( 'mysql', true ),
				'updated_at'            => $this->clock->now( 'mysql', true ),
			) );

			$this->archiveRepository->create( array(
				'enrollment_id'     => $enrollmentId,
				'student_person_id' => $studentPersonId,
				'parent_person_id'  => $guardianPersonId,
				'contract_no'       => $input->contractNo ?: null,
				'contract_date'     => $input->contractDate ?: null,
				'order_no'          => $input->orderNo ?: null,
				'order_date'        => $input->orderDate ?: null,
				'group_id'          => $input->groupId ?: null,
				'enrolled_at'       => $input->enrolledAt,
				'created_at'        => $this->clock->now( 'mysql', true ),
			) );

			$this->consentService->bindToPersons( $app->id, array(
				'self'     => $studentPersonId,
				'guardian' => $guardianPersonId,
			) );

			$this->auditService->record(
				AuditAction::EnrollStudent->value,
				'enrollment',
				$enrollmentId,
				array(
					'application_id' => $app->id,
					'group_id'       => $input->groupId,
				)
			);

			return array( $enrollmentId, $studentPersonId, $guardianPersonId );
		} );

		[ $enrollmentId, $studentPersonId, $guardianPersonId ] = $result;

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
					$studentUserId   = $this->userManager->create( array(
						'user_login'   => $studentLogin,
						'user_email'   => $studentEmail,
						'user_pass'    => $studentPassword,
						'display_name' => $studentDto->fullName(),
						'first_name'   => $studentDto->firstName,
						'last_name'    => $studentDto->lastName,
						'role'         => UserRole::FSStudent->value,
					) );
					$this->passwordGenerator->storeEncrypted( $studentUserId, $studentPassword );
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
					$guardianUserId   = $this->userManager->create( array(
						'user_login'   => $guardianEmail,
						'user_email'   => $guardianEmail,
						'user_pass'    => $guardianPassword,
						'display_name' => $parentDto->fullName(),
						'first_name'   => $parentDto->firstName,
						'last_name'    => $parentDto->lastName,
						'role'         => UserRole::FSParent->value,
					) );
					$this->passwordGenerator->storeEncrypted( $guardianUserId, $guardianPassword );
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
				$enrollmentId,
				$studentUserId,
				$guardianUserId,
				$studentLogin,
				$studentPassword,
				$guardianLogin,
				$guardianPassword,
				false
			);
		} catch ( \Throwable $e ) {
			$this->auditService->record(
				AuditAction::EnrollStudentFailed->value,
				'enrollment',
				$enrollmentId,
				array( 'error' => $e->getMessage() )
			);

			return new EnrollmentResultDTO( $enrollmentId, 0, 0, null, null, null, null, true );
		}
	}

	/**
	 * Восстанавливает ученика из архива: создаёт новую заявку на основе archive-записи
	 * (события 2A и 4B — старый ученик).
	 *
	 * @param int $archiveId ID архивной записи
	 *
	 * @return array{id: int, join_url: string}
	 *
	 * @throws RuntimeException|InvalidArgumentException
	 */
	public function restoreFromArchive( int $archiveId ): array {
		$archive = $this->archiveRepository->find( $archiveId );

		if ( null === $archive ) {
			throw new InvalidArgumentException( 'Архивная запись не найдена.' );
		}

		$studentPerson = $this->personRepository->find( $archive->studentPersonId );

		if ( null === $studentPerson ) {
			throw new RuntimeException( 'Запись ученика не найдена.' );
		}

		// Берём студенческие данные из snapshot последнего зачисления
		$studentData = array(
			'last_name'   => '',
			'first_name'  => '',
			'middle_name' => '',
			'birth_date'  => $studentPerson->birthDate ?? '',
			'email'       => '',
		);

		if ( $archive->enrollmentId !== null ) {
			$enrollment = $this->enrollmentRepository->find( $archive->enrollmentId );
			if ( $enrollment !== null && $enrollment->snapshotEnc !== null ) {
				try {
					$snap = json_decode( $this->crypto->decrypt( $enrollment->snapshotEnc ), true );
					if ( is_array( $snap['student'] ?? null ) ) {
						$studentData = array_merge( $studentData, $snap['student'] );
					}
				} catch ( \Throwable ) {}
			}
		}

		// full_name из persons (plain text) как fallback для имени
		if ( $studentData['last_name'] === '' && $studentPerson->fullName !== '' ) {
			$parts = explode( ' ', $studentPerson->fullName, 3 );
			$studentData['last_name']   = $parts[0] ?? '';
			$studentData['first_name']  = $parts[1] ?? '';
			$studentData['middle_name'] = $parts[2] ?? '';
		}

		// Период берём из группы (если есть)
		$periodId = '';
		if ( $archive->groupId !== null ) {
			$group    = $this->groupsRepository->findById( $archive->groupId );
			$periodId = $group?->period_id ?? '';
		}

		// Генерируем новый join-код
		$joinCode    = $this->joinCodeService->generate();
		$joinHash    = $this->joinCodeService->hash( $joinCode );
		$joinEnc     = $this->crypto->encrypt( $joinCode );
		$expiresAt   = gmdate( 'Y-m-d H:i:s', strtotime( '+48 hours' ) );

		$studentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $studentData ) );

		$now   = $this->clock->now( 'mysql', true );
		$appId = $this->applicationRepository->create( array(
			'student_person_id'    => $archive->studentPersonId,
			'period_id'            => $periodId,
			'status'               => ApplicationStatus::PendingParent->value,
			'join_code_hash'       => $joinHash,
			'join_code_enc'        => $joinEnc,
			'join_code_expires_at' => $expiresAt,
			'student_data_enc'     => $studentDataEnc,
			'created_at'           => $now,
			'updated_at'           => $now,
		) );

		if ( 0 === $appId ) {
			throw new RuntimeException( 'Не удалось создать заявку.' );
		}

		$this->auditService->record(
			AuditAction::EnrollStudent->value,
			'application',
			$appId,
			array( 'restored_from_archive_id' => $archiveId )
		);

		return array(
			'id'       => $appId,
			'join_url' => home_url( '/lms/join/' . $joinCode ),
		);
	}

	/**
	 * Привязывает существующего родителя к заявке (путь 3B и 4B).
	 * Загружает данные родителя из person_documents, шифрует и сохраняет в заявку.
	 * Переводит заявку в статус ready_for_review.
	 *
	 * @param int $applicationId  ID заявки (status = pending_parent)
	 * @param int $parentPersonId ID существующего родителя
	 *
	 * @throws InvalidArgumentException|DomainException
	 */
	public function selectExistingParent( int $applicationId, int $parentPersonId ): void {
		$app = $this->applicationRepository->find( $applicationId );

		if ( null === $app ) {
			throw new InvalidArgumentException( 'Заявка не найдена.' );
		}

		if ( ApplicationStatus::PendingParent !== $app->status ) {
			throw new DomainException( 'Заявка не в статусе pending_parent.' );
		}

		$parentPerson = $this->personRepository->find( $parentPersonId );

		if ( null === $parentPerson ) {
			throw new InvalidArgumentException( 'Родитель не найден.' );
		}

		// Строим parent_data из persons + person_documents
		$docs = $this->personDocumentsRepository->findByPersonId( $parentPersonId );

		$parentData = array(
			'last_name'       => '',
			'first_name'      => '',
			'middle_name'     => '',
			'birth_date'      => $parentPerson->birthDate ?? '',
			'email'           => '',
			'phone'           => '',
			'doc_type'        => '',
			'doc_number'      => '',
			'doc_issued_by'   => '',
			'doc_issued_date' => $docs?->docIssuedDate ?? '',
			'inn'             => '',
			'address'         => '',
			'relation_type'   => '',
		);

		// Раскрываем full_name
		$parts = explode( ' ', $parentPerson->fullName, 3 );
		$parentData['last_name']   = $parts[0] ?? '';
		$parentData['first_name']  = $parts[1] ?? '';
		$parentData['middle_name'] = $parts[2] ?? '';

		if ( $docs !== null ) {
			if ( $docs->emailEnc )         { $parentData['email']         = $this->crypto->decrypt( $docs->emailEnc ); }
			if ( $docs->phoneEnc )         { $parentData['phone']         = $this->crypto->decrypt( $docs->phoneEnc ); }
			if ( $docs->docNumberEnc )     { $parentData['doc_number']    = $this->crypto->decrypt( $docs->docNumberEnc ); }
			if ( $docs->docIssuedByEnc )   { $parentData['doc_issued_by'] = $this->crypto->decrypt( $docs->docIssuedByEnc ); }
			if ( $docs->innEnc )           { $parentData['inn']           = $this->crypto->decrypt( $docs->innEnc ); }
			if ( $docs->addressEnc )       { $parentData['address']       = $this->crypto->decrypt( $docs->addressEnc ); }
			$parentData['doc_type'] = $docs->docType ?? '';
		}

		$parentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $parentData ) );

		$now = $this->clock->now( 'mysql', true );
		$this->applicationRepository->update( $applicationId, array(
			'parent_person_id' => $parentPersonId,
			'parent_data_enc'  => $parentDataEnc,
			'status'           => ApplicationStatus::ReadyForReview->value,
			'updated_at'       => $now,
		) );
	}
}
