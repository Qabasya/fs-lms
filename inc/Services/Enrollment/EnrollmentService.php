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
use Inc\Enums\RelationType;
use Inc\Enums\UserRole;
use Inc\Managers\UserManager;
use Inc\Repositories\OptionsRepositories\StudentGroupMatrixRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Services\ConsentService;
use Inc\Services\EmailService;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\Person\PersonService;
use Inc\Services\Person\RelationshipService;
use Inc\Contracts\ClockInterface;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TransactionRunner;
use InvalidArgumentException;

readonly class EnrollmentService {

	use TransactionRunner;
	use RequestContextProvider;

	public function __construct(
		private ApplicationRepository      $applicationRepository,
		private EnrollmentRepository       $enrollmentRepository,
		private PersonRepository           $personRepository,
		private PersonService              $personService,
		private RelationshipService        $relationshipService,
		private ConsentService             $consentService,
		private AuditService               $auditService,
		private UserManager                $userManager,
		private PasswordGeneratorService   $passwordGenerator,
		private StudentGroupMatrixRepository $groupMatrix,
		private EmailService               $emailService,
		private PiiCryptoService           $crypto,
		private ClockInterface             $clock,
	) {}

	/**
	 * Выполняет зачисление студента по заявке.
	 *
	 * @param EnrollmentInputDTO $input Данные для зачисления
	 *
	 * @throws InvalidArgumentException Если заявка не найдена
	 * @throws DomainException          Если заявка не в статусе enrolling,
	 *                                  email родителя уже занят, или ученик уже зачислен
	 *
	 * @return EnrollmentResultDTO
	 */
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

		$studentDocHash  = $this->crypto->hash( $studentDto->docNumber );
		$guardianDocHash = $this->crypto->hash( $parentDto->docNumber );

		$existingStudent  = $this->personRepository->findByDocNumberHash( $studentDocHash );
		$existingGuardian = $this->personRepository->findByDocNumberHash( $guardianDocHash );

		if ( null === $existingGuardian && null !== $this->userManager->findByEmail( $parentDto->email ) ) {
			throw new DomainException( 'Email родителя уже занят другим пользователем.' );
		}

		if ( null !== $existingStudent && $this->enrollmentRepository->existsActive( $existingStudent->id, $input->subjectKey, $input->periodKey ) ) {
			throw new DomainException( 'Ученик уже зачислен на этот предмет в данный период.' );
		}

		// ===== Атомарная транзакция: создание записей в БД =====
		$result = $this->inTransaction( function () use ( $app, $input, $studentDto, $parentDto, $existingStudent, $existingGuardian ): array {
			$studentPersonId = $existingStudent !== null
				? $existingStudent->id
				: $this->personService->createOrFindBy( new PersonInputDTO(
					fullName:  $studentDto->fullName(),
					docNumber: $studentDto->docNumber,
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
					docType:   $parentDto->docType,
					birthDate: $parentDto->birthDate,
					inn:       $parentDto->inn,
					address:   $parentDto->address,
					phone:     $parentDto->phone,
					email:     $parentDto->email !== '' ? $parentDto->email : null,
				) );

			$relationType = RelationType::from( $parentDto->relationType );
			$this->relationshipService->addRepresentative( $guardianPersonId, $studentPersonId, $relationType, true );

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
				'subject_key'           => $input->subjectKey,
				'group_id'              => $input->groupId,
				'period_key'            => $input->periodKey,
				'enrolled_at'           => $input->enrolledAt,
				'status'                => 'active',
				'snapshot_enc'          => $this->crypto->encrypt( (string) wp_json_encode( $snapshot ) ),
				'source_application_id' => $app->id,
				'created_at'            => $this->clock->now( 'mysql', true ),
				'updated_at'            => $this->clock->now( 'mysql', true ),
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
					'subject_key'    => $input->subjectKey,
				)
			);

			return array( $enrollmentId, $studentPersonId, $guardianPersonId );
		} );

		[ $enrollmentId, $studentPersonId, $guardianPersonId ] = $result;

		// ===== Создание пользователей WP, привязка к группе, генерация паролей (вне транзакции) =====
		try {
			// --- Ученик ---
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

			// Прикрепить ученика к группе (матрица)
			$this->groupMatrix->addStudent( $input->groupId, $studentUserId );

			// --- Родитель ---
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

			// Удаление заявки (данные перешли в enrollment и persons)
			$this->applicationRepository->forceDelete( $app->id );

			if ( $input->sendEmailAuto ) {
				$this->emailService->sendWelcomeWithCredentials( $studentUserId, $studentPassword );
				$this->emailService->sendWelcomeWithCredentials( $guardianUserId, $guardianPassword );
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
}
