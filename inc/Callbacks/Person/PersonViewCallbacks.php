<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Enums\PiiAccessReason;
use Inc\Enums\PiiField;
use Inc\Enums\UserRole;
use Inc\Enums\WeekDay;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Person\PersonReader;
use Inc\Services\Person\PiiMaskingService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PersonViewCallbacks
 *
 * AJAX-коллбеки для просмотра данных лиц (Person).
 *
 * @package Inc\Callbacks\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Просмотр данных лица** — получение полной информации о человеке:
 *    - личные данные (маскированные PII)
 *    - связи (родитель-ученик)
 *    - зачисления (группы, расписание, договоры)
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику PersonReader, PasswordGeneratorService, PiiMaskingService.
 * Работает с репозиториями PersonRepository, StudentRecordRepository, GroupsRepository.
 * Использует для отображения маскированные PII-поля (без раскрытия).
 */
class PersonViewCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize()
	use Sanitizer;   // Трейт с методами sanitizeInt()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param PersonReader              $personReader              Сервис безопасного чтения PII
	 * @param PersonRepository          $personRepository          Репозиторий лиц
	 * @param StudentRecordRepository   $studentRecordRepository   Репозиторий записей студентов
	 * @param GroupsRepository          $groupRepository           Репозиторий групп
	 * @param SubjectRepository         $subjectRepository         Репозиторий предметов
	 * @param PasswordGeneratorService  $passwordGenerator         Сервис генерации паролей
	 * @param PiiMaskingService         $maskingService            Сервис маскирования PII
	 * @param PersonDocumentsRepository $personDocumentsRepository Репозиторий документов
	 */
	public function __construct(
		private readonly PersonReader              $personReader,
		private readonly PersonRepository          $personRepository,
		private readonly StudentRecordRepository   $studentRecordRepository,
		private readonly GroupsRepository          $groupRepository,
		private readonly SubjectRepository         $subjectRepository,
		private readonly PasswordGeneratorService  $passwordGenerator,
		private readonly PiiMaskingService         $maskingService,
		private readonly PersonDocumentsRepository $personDocumentsRepository,
	) {
		parent::__construct();
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

		// Определение типа (ученик, родитель, неизвестно)
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
				);
			}
		}

		// Сбор данных о зачислениях
		$personIds   = $isStudent ? array( $personId ) : array_column( $dependents, 'student_person_id' );
		$enrollments = array();
		$nameMap     = array_column( $dependents, 'name', 'student_person_id' );

		// Получение всех предметов для подстановки названий
		$allSubjects = array();
		foreach ( $this->subjectRepository->readAll() as $subjectDto ) {
			$allSubjects[ $subjectDto->key ] = $subjectDto->name;
		}

		foreach ( $personIds as $pid ) {
			$sPerson = $pid === $personId ? $person : $this->personRepository->find( $pid );

			foreach ( $this->studentRecordRepository->findActiveByStudent( $pid ) as $record ) {
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
					'contract_no'   => $record->contractNo   ?? '',
					'contract_date' => $record->contractDate ?? '',
					'order_no'      => $record->orderNo      ?? '',
					'order_date'    => $record->orderDate    ?? '',
					'last_name'     => $sPerson?->lastName   ?? '',
					'first_name'    => $sPerson?->firstName  ?? '',
					'middle_name'   => $sPerson?->middleName ?? '',
					'birth_date'    => $sPerson?->birthDate  ?? '',
					'school'        => $sPerson?->school     ?? '',
					'grade'         => $sPerson?->grade      ?? '',
				);
			}
		}

		// Данные для аутентификации
		$credentials = $person->wpUserId ? $this->passwordGenerator->getCredentials( $person->wpUserId ) : null;
		$maskedPii   = $this->getMaskedPersonPii( $personId );

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
				PiiAccessReason::AdminMaskedView->value
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

		$raw      = $group->meetings ?? null;
		$schedule = is_string( $raw ) ? ( json_decode( $raw, true ) ?: array() ) : ( is_array( $raw ) ? $raw : array() );

		if ( empty( $schedule ) ) {
			return '';
		}

		return WeekDay::formatSchedule( $schedule );
	}
}