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
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Services\Person\ConsentService;
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
		private EnrollmentPersonResolver     $personResolver,
		private EnrollmentTransaction        $transaction,
		private AccountProvisioningService   $provisioning,
	) {}

	/**
	 * Зачисляет ученика по заявке.
	 *
	 * ### Порядок и почему он такой
	 *
	 * 1. Валидация заявки и расшифровка данных ученика/родителя.
	 * 2. Поиск уже существующих физлиц ({@see EnrollmentPersonResolver}).
	 * 3. Атомарная запись ({@see EnrollmentTransaction}): физлица + запись о
	 *    зачислении + согласия.
	 * 4. После COMMIT — то, что транзакцией не откатывается: WP-учётки
	 *    ({@see AccountProvisioningService}), удаление заявки, письмо.
	 *
	 * Падение шага 4 не отменяет зачисление: заявка помечается `converted`,
	 * учётки досоздаёт `RecoveryService` (cron), а вызывающему возвращается
	 * результат с флагом ошибки.
	 *
	 * @param EnrollmentInputDTO $input Параметры зачисления
	 *
	 * @throws InvalidArgumentException Заявка не найдена
	 * @throws DomainException          Заявка не в статусе enrolling, email родителя занят,
	 *                                  ученик уже зачислен в эту группу
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

		$existingStudent  = $this->personResolver->resolveStudent( $app, $studentDto );
		$existingGuardian = $this->personResolver->resolveGuardian( $app, $parentDto );

		if ( null === $existingGuardian && null !== $this->userManager->findByEmail( $parentDto->email ) ) {
			throw new DomainException( 'Email родителя уже занят другим пользователем.' );
		}

		if ( null !== $existingStudent && $this->studentRecordRepository->existsActive( $existingStudent->id, $input->groupId ) ) {
			throw new DomainException( 'Ученик уже зачислен в эту группу.' );
		}

		[ $recordId, $studentPersonId, $guardianPersonId ] = $this->transaction->run(
			$app,
			$input,
			$studentDto,
			$parentDto,
			$existingStudent,
			$existingGuardian
		);

		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( get_current_user_id(), AuditAction::EnrollStudent, $studentPersonId, $recordId, $input->groupId )
		);

		// Generic-сейм для опциональных модулей. Без подписчиков — no-op.
		do_action( 'fs_lms_student_enrolled', $recordId, $studentPersonId );

		try {
			$student  = $this->provisioning->provisionStudent(
				$studentPersonId,
				$this->studentAccountData( $studentDto ),
				$this->studentLogin( $studentDto, $studentPersonId ),
				'' !== $studentDto->loginPassword ? $studentDto->loginPassword : $this->passwordGenerator->generatePlain()
			);
			$guardian = $this->provisioning->provisionParent( $guardianPersonId, $parentDto );

			$this->applicationRepository->forceDelete( $app->id );

			if ( $input->sendEmailAuto ) {
				// Письмо с данными для входа уходит только родителю — учётные данные ученика
				// родитель передаёт ему сам (учитель/офис видят их в карточке зачисления).
				$this->emailService->sendWelcomeWithCredentials( $guardian->userId, $guardian->password, array(
					'student_full_name'  => $studentDto->fullName(),
					'parent_first_name'  => $parentDto->firstName,
					'parent_middle_name' => $parentDto->middleName,
				) );
			}

			return new EnrollmentResultDTO(
				$recordId,
				$student->userId,
				$guardian->userId,
				$student->login,
				$student->password,
				$guardian->login,
				$guardian->password,
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

	/**
	 * Данные ученика для провизии учётки (email + ФИО).
	 *
	 * @param StudentDataDTO $student Данные ученика из заявки
	 */
	private function studentAccountData( StudentDataDTO $student ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:  $student->lastName,
			firstName: $student->firstName,
			docNumber: $student->docNumber,
			isStudent: true,
			email:     '' !== $student->email ? $student->email : null,
		);
	}

	/**
	 * Логин новой учётки ученика: явный из заявки → email → служебный по ID физлица.
	 *
	 * @param StudentDataDTO $student  Данные ученика
	 * @param int            $personId Физлицо ученика
	 */
	private function studentLogin( StudentDataDTO $student, int $personId ): string {
		if ( '' !== $student->username ) {
			return $student->username;
		}

		return '' !== $student->email ? $student->email : 'student_' . $personId;
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
