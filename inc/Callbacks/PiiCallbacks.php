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
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
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

/**
 * Class PiiCallbacks
 *
 * AJAX-коллбеки и страницы административной панели для работы с персональными данными (PII).
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Раскрытие PII** — временное раскрытие зашифрованных данных (с лимитом).
 * 2. **Управление лицами** — создание, обновление, мягкое удаление.
 * 3. **Управление представителями** — добавление и замена законных представителей.
 * 4. **Просмотр данных** — получение данных лица для отображения в админ-панели.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику PersonReader, PersonService, StudentRecordRepository.
 * Использует трейты Authorizer и Sanitizer для авторизации и очистки данных.
 */
class PiiCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), error(), success()
	use Sanitizer;   // Трейт с методами sanitizeInt(), sanitizeText(), requireText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param PersonReader              $personReader          Сервис безопасного чтения PII
	 * @param PersonService             $personService         Сервис управления лицами
	 * @param PersonRepository          $personRepository      Репозиторий лиц
	 * @param PersonDocumentsRepository $personDocumentsRepository Репозиторий документов
	 * @param StudentRecordRepository   $studentRecordRepository Репозиторий записей студентов
	 * @param RateLimitService          $rateLimitService      Сервис ограничения запросов
	 * @param EmailService              $emailService          Сервис отправки email
	 * @param AuditService              $auditService          Сервис аудита
	 * @param GroupsRepository          $groupRepository       Репозиторий групп
	 * @param SubjectRepository         $subjectRepository     Репозиторий предметов
	 * @param PiiCryptoService          $crypto                Сервис шифрования PII
	 * @param PiiMaskingService         $maskingService        Сервис маскирования PII
	 * @param PasswordGeneratorService  $passwordGenerator     Сервис генерации паролей
	 */
	public function __construct(
		private readonly PersonReader              $personReader,
		private readonly PersonService             $personService,
		private readonly PersonRepository          $personRepository,
		private readonly PersonDocumentsRepository $personDocumentsRepository,
		private readonly StudentRecordRepository   $studentRecordRepository,
		private readonly RateLimitService          $rateLimitService,
		private readonly EmailService              $emailService,
		private readonly AuditService              $auditService,
		private readonly GroupsRepository          $groupRepository,
		private readonly SubjectRepository         $subjectRepository,
		private readonly PiiCryptoService          $crypto,
		private readonly PiiMaskingService         $maskingService,
		private readonly PasswordGeneratorService  $passwordGenerator,
	) {
		parent::__construct();
	}

	/**
	 * Раскрывает одно PII-поле на 30 секунд.
	 *
	 * @return void
	 */
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

	/**
	 * Запрос на удаление ПД (soft delete).
	 *
	 * @return void
	 */
	public function ajaxRequestPiiDeletion(): void {
		$this->authorize( Nonce::RequestPiiDeletion, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );

		$this->personService->softDelete( $personId, get_current_user_id() );

		$this->success();
	}

	/**
	 * Добавляет представителя к студенту.
	 *
	 * @return void
	 */
	public function ajaxAddRepresentative(): void {
		$this->authorize( Nonce::AddRepresentative, Capability::ManagePersons );

		$studentPersonId = $this->sanitizeInt( 'student_person_id' );

		$guardianPersonId = $this->personService->createOrFindBy( new PersonInputDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			docNumber:  $this->requireText( 'doc_number' ),
			isStudent:  false,
			middleName: $this->sanitizeText( 'middle_name' ),
			inn:        $this->sanitizeText( 'inn' ),
			address:    $this->sanitizeText( 'address' ),
			phone:      $this->sanitizeText( 'phone' ),
			email:      $this->sanitizeText( 'email' ) ?: null,
		) );

		$record = $this->studentRecordRepository->findActiveByStudentFirst( $studentPersonId );

		if ( $record !== null ) {
			$this->studentRecordRepository->update( $record->id, array( 'parent_person_id' => $guardianPersonId ) );
		}

		$this->success();
	}

	/**
	 * Заменяет представителя у записи студента.
	 *
	 * @return void
	 */
	public function ajaxReplaceRepresentative(): void {
		$this->authorize( Nonce::ReplaceRepresentative, Capability::ManagePersons );

		$recordId = $this->sanitizeInt( 'archive_id' );

		$newGuardianId = $this->personService->createOrFindBy( new PersonInputDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			docNumber:  $this->requireText( 'doc_number' ),
			isStudent:  false,
			middleName: $this->sanitizeText( 'middle_name' ),
			inn:        $this->sanitizeText( 'inn' ),
			email:      $this->sanitizeText( 'email' ) ?: null,
		) );

		$this->studentRecordRepository->update( $recordId, array( 'parent_person_id' => $newGuardianId ) );

		$this->success();
	}

	/**
	 * Обновляет данные лица (person).
	 *
	 * @return void
	 */
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
			'phone'          => $this->sanitizeText( 'phone' ),
			'email'          => $this->sanitizeText( 'email' ),
			'birth_date'     => $this->sanitizeText( 'birth_date' ),
			'doc_number'     => $this->sanitizeText( 'doc_number' ),
			'inn'            => $this->sanitizeText( 'inn' ),
			'address'        => $this->sanitizeText( 'address' ),
			'doc_issued_by'  => $this->sanitizeText( 'doc_issued_by' ),
			'doc_issued_date' => $this->sanitizeText( 'doc_issued_date' ),
		) );

		if ( $lastName ) { $personChanges['last_name']   = $lastName; }
		if ( $firstName ) { $personChanges['first_name'] = $firstName; }
		if ( $middleName ) { $personChanges['middle_name'] = $middleName; }

		if ( ! empty( $personChanges ) ) {
			$this->personService->update( $personId, $personChanges, get_current_user_id() );
		}

		// Обновление пользователя WP, если привязан
		if ( $person->wpUserId ) {
			$userData = array( 'ID' => $person->wpUserId );

			$newLogin = $this->sanitizeText( 'login' );
			if ( $newLogin ) {
				$userData['user_login'] = $newLogin;
			}

			if ( isset( $personChanges['email'] ) ) {
				$userData['user_email'] = $personChanges['email'];
			}

			if ( $lastName || $firstName ) {
				$userData['display_name'] = trim( $lastName . ' ' . $firstName . ' ' . $middleName );
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
				} catch ( \RuntimeException ) {
					// Логирование ошибки
				}
			}
		}

		// Обновление данных ребёнка (для родителя)
		$childDocNumber = $this->sanitizeText( 'child_doc_number' );
		$childInn       = $this->sanitizeText( 'child_inn' );
		$childBirthDate = $this->sanitizeText( 'child_birth_date' );

		if ( $childDocNumber || $childInn || $childBirthDate ) {
			$dependents = $this->studentRecordRepository->findActiveByParent( $personId );
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

	/**
	 * Получает данные лица для отображения в админ-панели.
	 *
	 * @return void
	 */
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

		// Для студента — поиск представителя
		if ( $isStudent ) {
			$activeRecord = $this->studentRecordRepository->findActiveByStudentFirst( $personId );
			if ( $activeRecord !== null ) {
				$gPerson = $this->personRepository->find( $activeRecord->parentPersonId );
				$gUser   = $gPerson?->wpUserId ? get_userdata( $gPerson->wpUserId ) : null;
				$representatives[] = array(
					'archive_id'         => $activeRecord->id,
					'guardian_person_id' => $activeRecord->parentPersonId,
					'name'               => $gUser ? $gUser->display_name : ( $gPerson?->fullName() ?: "Person #{$activeRecord->parentPersonId}" ),
					'type_label'         => 'Родитель',
					'since'              => substr( $activeRecord->enrolledAt, 0, 10 ),
					'person_url'         => admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $activeRecord->parentPersonId ),
				);
			}
		}

		// Для родителя — поиск зависимых учеников
		if ( $isParent ) {
			foreach ( $this->studentRecordRepository->findActiveByParent( $personId ) as $record ) {
				$sPerson = $this->personRepository->find( $record->studentPersonId );
				$sUser   = $sPerson?->wpUserId ? get_userdata( $sPerson->wpUserId ) : null;
				$dependents[] = array(
					'archive_id'        => $record->id,
					'student_person_id' => $record->studentPersonId,
					'name'              => $sUser ? $sUser->display_name : ( $sPerson?->fullName() ?: "Person #{$record->studentPersonId}" ),
					'type_label'        => 'Ученик',
					'since'             => substr( $record->enrolledAt, 0, 10 ),
					'person_url'        => admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $record->studentPersonId ),
				);
			}
		}

		// Сбор данных о зачислениях
		$personIds   = $isStudent ? array( $personId ) : array_column( $dependents, 'student_person_id' );
		$enrollments = array();
		$nameMap     = array_column( $dependents, 'name', 'student_person_id' );

		$allSubjects = array();
		foreach ( $this->subjectRepository->readAll() as $subjectDto ) {
			$allSubjects[ $subjectDto->key ] = $subjectDto->name;
		}

		foreach ( $personIds as $pid ) {
			$sPerson = $pid === $personId ? $person : $this->personRepository->find( $pid );

			foreach ( $this->studentRecordRepository->findByStudent( $pid ) as $record ) {
				$group = $record->groupId ? $this->groupRepository->findById( $record->groupId ) : null;

				$enrollments[] = array(
					'record_id'     => $record->id,
					'student_name'  => $isParent ? ( $nameMap[ $pid ] ?? "#{$pid}" ) : null,
					'group_id'      => $record->groupId,
					'group_title'   => $group?->name ?? '—',
					'subject_key'   => $group?->subject_key ?? '',
					'subject_name'  => $allSubjects[ $group?->subject_key ?? '' ] ?? ( $group?->subject_key ?? '—' ),
					'schedule'      => $this->formatSchedule( $group ),
					'status_label'  => $record->status->label(),
					'status_value'  => $record->status->value,
					'enrolled_at'   => substr( $record->enrolledAt, 0, 10 ),
					'terminated_at' => $record->expelledAt ? substr( $record->expelledAt, 0, 10 ) : null,
					'contract_no'   => $record->contractNo ?? '',
					'last_name'     => $sPerson?->lastName ?? '',
					'first_name'    => $sPerson?->firstName ?? '',
					'middle_name'   => $sPerson?->middleName ?? '',
					'birth_date'    => $sPerson?->birthDate ?? '',
					'school'        => $sPerson?->school ?? '',
					'grade'         => $sPerson?->grade  ?? '',
					'child_doc_number'    => '',
					'child_inn'           => '',
					'child_birth_date'    => '',
					'guardian_birth_date' => '',
					'student_phone'       => '',
					'guardian_phone'      => '',
				);
			}
		}

		$credentials = $person->wpUserId ? $this->passwordGenerator->getCredentials( $person->wpUserId ) : null;

		$maskedPii = $this->getMaskedPersonPii( $personId );

		if ( $isParent ) {
			$docs = $this->personDocumentsRepository->findByPersonId( $personId );
			$maskedPii['doc_issued_by']   = ( $docs !== null && $docs->docIssuedByEnc !== null )
				? '••••••'
				: '';
			$maskedPii['doc_issued_date'] = $docs?->docIssuedDate ?? '';
		}

		$this->success( array(
			'type'            => $type,
			'wp_user_id'      => $person->wpUserId ?? 0,
			'display_name'    => $wpUser ? $wpUser->display_name : $person->fullName(),
			'login'           => $wpUser ? $wpUser->user_login : '',
			'email'           => $wpUser ? $wpUser->user_email : '',
			'password'        => $credentials['password'] ?? '',
			'masked_pii'      => $maskedPii,
			'representatives' => $representatives,
			'dependents'      => $dependents,
			'enrollments'     => $enrollments,
		) );
	}

	/**
	 * Раскрывает все PII-поля лица (для администраторов).
	 *
	 * @return void
	 */
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

			$issuedParts = $this->getDocIssuedParts( $personId );
			$payload['doc_issued_by']   = $issuedParts['by'];
			$payload['doc_issued_date'] = $issuedParts['date'];

			$this->success( $payload );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Получает данные о выдаче документа (кем и когда выдан).
	 *
	 * @param int $personId ID лица
	 *
	 * @return array
	 */
	private function getDocIssuedParts( int $personId ): array {
		$docs = $this->personDocumentsRepository->findByPersonId( $personId );
		if ( null === $docs ) {
			return array( 'by' => '', 'date' => '' );
		}

		$by = '';
		if ( $docs->docIssuedByEnc !== null ) {
			try {
				$by = trim( $this->crypto->decrypt( $docs->docIssuedByEnc ) );
			} catch ( \Throwable ) {
				// Не удалось расшифровать
			}
		}

		return array( 'by' => $by, 'date' => $docs->docIssuedDate ?? '' );
	}

	/**
	 * Возвращает замаскированные PII-поля для отображения в интерфейсе.
	 *
	 * @param int $personId ID лица
	 *
	 * @return array
	 */
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
				'phone'      => $this->maskingService->mask( $dto->phone,   PiiField::Phone ),
			);
		} catch ( \Throwable ) {
			return array(
				'doc_number' => $this->maskingService->placeholder( PiiField::Pass ),
				'inn'        => $this->maskingService->placeholder( PiiField::Inn ),
				'address'    => '',
			);
		}
	}

	/**
	 * Форматирует расписание группы для отображения.
	 *
	 * @param mixed $group Объект группы
	 *
	 * @return string
	 */
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

	/**
	 * Страница списка лиц (Persons).
	 *
	 * @return void
	 */
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

	/**
	 * Детальная страница лица (Person).
	 *
	 * @return void
	 */
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