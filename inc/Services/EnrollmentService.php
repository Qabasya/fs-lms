<?php

declare( strict_types=1 );

namespace Inc\Services;

use DomainException;
use Inc\DTO\EnrollmentInputDTO;
use Inc\DTO\EnrollmentResultDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\RelationType;
use Inc\Enums\UserRole;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TransactionRunner;
use InvalidArgumentException;

/**
 * Class EnrollmentService
 *
 * Сервис для зачисления студентов по заявке.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Валидация заявки** — проверка статуса, данных, дубликатов.
 * 2. **Создание записей в БД** — создание/поиск лиц (Persons), связей (Relationships), зачисления (Enrollment).
 * 3. **Создание пользователей WP** — создание пользователей для студента и родителя.
 * 4. **Генерация ссылок паролей** — отправка email или возврат ссылок для ручной передачи.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с БД репозиториям (ApplicationRepository, EnrollmentRepository, PersonRepository),
 * а вспомогательные операции — сервисам (PersonService, RelationshipService, ConsentService).
 * Использует трейты TransactionRunner (атомарность) и RequestContextProvider (IP/UA).
 */
readonly class EnrollmentService {

	use TransactionRunner;        // Трейт с методом inTransaction() для атомарных операций
	use RequestContextProvider;   // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор сервиса.
	 *
	 * @param ApplicationRepository $applicationRepository Репозиторий заявок
	 * @param EnrollmentRepository  $enrollmentRepository  Репозиторий зачислений
	 * @param PersonRepository      $personRepository      Репозиторий лиц
	 * @param PersonService         $personService         Сервис управления лицами
	 * @param RelationshipService   $relationshipService   Сервис связей опекун-ученик
	 * @param ConsentService        $consentService        Сервис согласий
	 * @param AuditService          $auditService          Сервис аудита
	 * @param UserManager           $userManager           Менеджер пользователей WP
	 * @param PasswordLinkService   $passwordLinkService   Сервис генерации ссылок паролей
	 * @param EmailService          $emailService          Сервис отправки email
	 * @param PiiCryptoService      $crypto                Сервис шифрования PII
	 */
	public function __construct(
		private ApplicationRepository $applicationRepository,
		private EnrollmentRepository  $enrollmentRepository,
		private PersonRepository      $personRepository,
		private PersonService         $personService,
		private RelationshipService   $relationshipService,
		private ConsentService        $consentService,
		private AuditService          $auditService,
		private UserManager           $userManager,
		private PasswordLinkService   $passwordLinkService,
		private EmailService          $emailService,
		private PiiCryptoService      $crypto,
	) {}

	/**
	 * Выполняет зачисление студента по заявке.
	 *
	 * @param EnrollmentInputDTO $input Данные для зачисления
	 *
	 * @throws InvalidArgumentException Если заявка не найдена
	 * @throws DomainException          Если заявка не в статусе ready_for_review,
	 *                                  email родителя уже занят, или ученик уже зачислен
	 *
	 * @return EnrollmentResultDTO
	 */
	public function enroll( EnrollmentInputDTO $input ): EnrollmentResultDTO {
		$app = $this->applicationRepository->find( $input->applicationId );

		if ( null === $app ) {
			throw new InvalidArgumentException( 'Заявка не найдена.' );
		}

		// Только заявки в статусе ready_for_review можно зачислить
		if ( ApplicationStatus::ReadyForReview !== $app->status ) {
			throw new DomainException( 'Заявка не в статусе ready_for_review.' );
		}

		// Расшифровка данных студента и родителя
		$studentData = json_decode( $this->crypto->decrypt( (string) $app->studentDataEnc ), true );
		$parentData  = json_decode( $this->crypto->decrypt( (string) $app->parentDataEnc ), true );

		// Хэши номеров документов для поиска существующих лиц
		$studentDocHash  = $this->crypto->hash( (string) $studentData['doc_number'] );
		$guardianDocHash = $this->crypto->hash( (string) $parentData['doc_number'] );

		$existingStudent  = $this->personRepository->findByDocNumberHash( $studentDocHash );
		$existingGuardian = $this->personRepository->findByDocNumberHash( $guardianDocHash );

		// Проверка: email родителя не должен принадлежать другому пользователю WP
		if ( null === $existingGuardian && null !== $this->userManager->findByEmail( (string) $parentData['email'] ) ) {
			throw new DomainException( 'Email родителя уже занят другим пользователем.' );
		}

		// Проверка: ученик не должен быть уже зачислен на этот предмет в этот период
		if ( null !== $existingStudent && $this->enrollmentRepository->existsActive( $existingStudent->id, $input->subjectKey, $input->periodKey ) ) {
			throw new DomainException( 'Ученик уже зачислен на этот предмет в данный период.' );
		}

		// ===== Атомарная транзакция: создание записей в БД =====
		$result = $this->inTransaction( function () use ( $app, $input, $studentData, $parentData, $existingStudent, $existingGuardian ): array {
			// Создание/поиск записи ученика
			$studentPersonId = $existingStudent !== null
				? $existingStudent->id
				: $this->personService->createOrFindBy( array(
					'full_name'  => $studentData['full_name'],
					'doc_number' => $studentData['doc_number'],
					'inn'        => $studentData['inn'] ?? '',
					'email'      => $studentData['email'] ?? null,
				) );

			// Создание/поиск записи родителя
			$guardianPersonId = $existingGuardian !== null
				? $existingGuardian->id
				: $this->personService->createOrFindBy( array(
					'full_name'  => $parentData['full_name'],
					'doc_number' => $parentData['doc_number'],
					'inn'        => $parentData['inn'] ?? '',
					'address'    => $parentData['address'] ?? '',
					'phone'      => $parentData['phone'] ?? '',
					'email'      => $parentData['email'],
				) );

			// Создание связи опекун-ученик
			$relationType = RelationType::from( (string) $parentData['relation_type'] );
			$this->relationshipService->addRepresentative( $guardianPersonId, $studentPersonId, $relationType, true );

			// Создание зачисления (enrollment) со снимком данных
			$snapshot    = array(
				'student'  => $studentData,
				'guardian' => $parentData,
				'enrolled_at' => $input->enrolledAt,
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
				'created_at'            => current_time( 'mysql', true ),
				'updated_at'            => current_time( 'mysql', true ),
			) );

			// Привязка согласий к созданным лицам
			$this->consentService->bindToPersons( $app->id, array(
				'self'     => $studentPersonId,
				'guardian' => $guardianPersonId,
			) );

			// Логирование аудита
			$this->auditService->record(
				AuditAction::EnrollStudent->value,
				'enrollment',
				$enrollmentId,
				array(
					'application_id' => $app->id,
					'subject_key'    => $input->subjectKey,
				)
			);

			// Перевод заявки в статус "зачисляется"
			$this->applicationRepository->setStatus( $app->id, ApplicationStatus::Enrolling );

			return array( $enrollmentId, $studentPersonId, $guardianPersonId );
		} );

		[ $enrollmentId, $studentPersonId, $guardianPersonId ] = $result;

		// ===== Создание пользователей WordPress (вне транзакции) =====
		try {
			// Создание пользователя-студента
			$studentPerson = $this->personRepository->find( $studentPersonId );
			if ( null !== $studentPerson && null !== $studentPerson->wpUserId ) {
				$studentUserId = $studentPerson->wpUserId;
			} else {
				$studentEmail = (string) ( $studentData['email'] ?? '' );
				$existingUser = $studentEmail !== '' ? $this->userManager->findByEmail( $studentEmail ) : null;
				if ( null !== $existingUser ) {
					$studentUserId = $existingUser->ID;
				} else {
					$studentUserId = $this->userManager->create( array(
						'user_login'   => $studentEmail !== '' ? $studentEmail : 'student_' . $studentPersonId,
						'user_email'   => $studentEmail,
						'user_pass'    => wp_generate_password( 64 ),
						'display_name' => (string) $studentData['full_name'],
						'role'         => UserRole::FSStudent->value,
					) );
				}
				if ( null !== $studentPerson && null === $studentPerson->wpUserId ) {
					$this->personRepository->setWpUser( $studentPersonId, $studentUserId );
					$this->userManager->setPersonId( $studentUserId, $studentPersonId );
				}
			}

			// Создание пользователя-родителя
			$guardianPerson = $this->personRepository->find( $guardianPersonId );
			if ( null !== $guardianPerson && null !== $guardianPerson->wpUserId ) {
				$guardianUserId = $guardianPerson->wpUserId;
			} else {
				$guardianEmail    = (string) $parentData['email'];
				$existingGuardianUser = $this->userManager->findByEmail( $guardianEmail );
				if ( null !== $existingGuardianUser ) {
					$guardianUserId = $existingGuardianUser->ID;
				} else {
					$guardianUserId = $this->userManager->create( array(
						'user_login'   => $guardianEmail,
						'user_email'   => $guardianEmail,
						'user_pass'    => wp_generate_password( 64 ),
						'display_name' => (string) $parentData['full_name'],
						'role'         => UserRole::FSParent->value,
					) );
				}
				if ( null !== $guardianPerson && null === $guardianPerson->wpUserId ) {
					$this->personRepository->setWpUser( $guardianPersonId, $guardianUserId );
					$this->userManager->setPersonId( $guardianUserId, $guardianPersonId );
				}
			}

			// Завершение заявки: статус converted
			$this->applicationRepository->markConverted( $app->id, $enrollmentId );

			// Генерация ссылки для установки пароля родителю
			$guardianLink = $this->passwordLinkService->generate( $guardianUserId );

			// Отправка email с ссылкой (если требуется)
			if ( $input->sendEmailAuto ) {
				$this->emailService->sendPasswordSetup( $guardianUserId, $guardianLink );
				$guardianLink = null;
			}

			return new EnrollmentResultDTO( $enrollmentId, $studentUserId, $guardianUserId, null, $guardianLink, false );
		} catch ( \Throwable $e ) {
			// Логирование ошибки создания пользователей
			$this->auditService->record(
				AuditAction::EnrollStudentFailed->value,
				'enrollment',
				$enrollmentId,
				array( 'error' => $e->getMessage() )
			);

			// Возвращаем результат с флагом partialFailure (будет обработано cron-задачей)
			return new EnrollmentResultDTO( $enrollmentId, 0, 0, null, null, true );
		}
	}
}