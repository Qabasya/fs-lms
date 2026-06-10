<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Enums\PiiField;
use Inc\Enums\UserRole;
use Inc\Enums\WeekDay;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\Person\PersonReader;
use Inc\Services\Person\PiiMaskingService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class PersonViewCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

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

		$personIds   = $isStudent ? array( $personId ) : array_column( $dependents, 'student_person_id' );
		$enrollments = array();
		$nameMap     = array_column( $dependents, 'name', 'student_person_id' );

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
}
