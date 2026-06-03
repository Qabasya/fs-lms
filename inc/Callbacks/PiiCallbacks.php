<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\PersonInputDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Enums\PiiField;
use Inc\Enums\RelationType;
use Inc\Enums\UserRole;
use Inc\Enums\WeekDay;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Services\EmailService;
use Inc\Services\Person\PersonReader;
use Inc\Services\Person\PersonService;
use Inc\Services\Person\PiiMaskingService;
use Inc\Services\Person\RelationshipService;
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
 * 1. **Раскрытие PII-полей** — временное раскрытие зашифрованных данных на 30 секунд.
 * 2. **Управление лицами (Persons)** — создание, обновление, мягкое удаление записей.
 * 3. **Управление представителями** — добавление и замена законных представителей учеников.
 * 4. **Отображение страниц** — рендеринг списков лиц и детальных карточек.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику PersonReader, PersonService, RelationshipService.
 * Управляет отображением страниц и AJAX-операциями в админ-панели.
 */
class PiiCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/**
	 * Конструктор коллбеков.
	 *
	 * @param PersonReader        $personReader        Сервис безопасного чтения PII
	 * @param PersonService       $personService       Сервис управления лицами
	 * @param PersonRepository    $personRepository    Репозиторий лиц
	 * @param RelationshipService $relationshipService Сервис управления связями
	 * @param RateLimitService    $rateLimitService    Сервис ограничения запросов
	 * @param EmailService        $emailService        Сервис отправки email
	 * @param AuditService        $auditService        Сервис аудита
	 * @param PiiMaskingService   $maskingService      Сервис маскирования PII
	 */
	public function __construct(
		private readonly PersonReader           $personReader,
		private readonly PersonService          $personService,
		private readonly PersonRepository       $personRepository,
		private readonly RelationshipService    $relationshipService,
		private readonly RateLimitService       $rateLimitService,
		private readonly EmailService           $emailService,
		private readonly AuditService           $auditService,
		private readonly EnrollmentRepository   $enrollmentRepository,
		private readonly StudentGroupRepository $groupRepository,
		private readonly SubjectRepository      $subjectRepository,
		private readonly PiiCryptoService       $crypto,
		private readonly PiiMaskingService      $maskingService,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: раскрыть одно PII-поле на 30 секунд.
	 *
	 * @return void
	 */
	public function ajaxRevealPiiField(): void {
		$this->authorize( Nonce::RevealPii, Capability::ViewPII );

		// Лимит раскрытий на пользователя
		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			// 429 — HTTP-статус "Too Many Requests"
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
	 * AJAX: запросить удаление ПД (soft delete).
	 *
	 * @return void
	 */
	public function ajaxRequestPiiDeletion(): void {
		$this->authorize( Nonce::RequestPiiDeletion, Capability::ManagePersons );

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );

		// Мягкое удаление (заполняется поле deleted_at)
		$this->personService->softDelete( $personId, get_current_user_id() );

		$this->success();
	}

	/**
	 * AJAX: добавить нового представителя к ученику.
	 *
	 * @return void
	 */
	public function ajaxAddRepresentative(): void {
		$this->authorize( Nonce::AddRepresentative, Capability::ManagePersons );

		$studentPersonId = $this->sanitizeInt( $_POST['student_person_id'] ?? 0 );
		$relationType    = \Inc\Enums\RelationType::from( $this->requireKey( $_POST['relation_type'] ?? '' ) );

		// Поиск или создание опекуна по уникальным полям
		$guardianPersonId = $this->personService->createOrFindBy( new PersonInputDTO(
			fullName:  $this->requireText( $_POST['full_name'] ?? '' ),
			docNumber: $this->requireText( $_POST['doc_number'] ?? '' ),
			inn:       $this->sanitizeText( $_POST['inn'] ?? '' ),
			address:   $this->sanitizeText( $_POST['address'] ?? '' ),
			phone:     $this->sanitizeText( $_POST['phone'] ?? '' ),
			email:     $this->sanitizeText( $_POST['email'] ?? '' ) ?: null,
		) );

		$this->relationshipService->addRepresentative(
			$guardianPersonId,
			$studentPersonId,
			$relationType,
			$isPrimary
		);

		$this->success();
	}

	/**
	 * AJAX: заменить представителя.
	 *
	 * @return void
	 */
	public function ajaxReplaceRepresentative(): void {
		$this->authorize( Nonce::ReplaceRepresentative, Capability::ManagePersons );

		$oldRelId = $this->sanitizeInt( $_POST['relationship_id'] ?? 0 );
		$newType  = \Inc\Enums\RelationType::from( $this->requireKey( $_POST['relation_type'] ?? '' ) );

		$newGuardianId = $this->personService->createOrFindBy( new PersonInputDTO(
			fullName:  $this->requireText( $_POST['full_name'] ?? '' ),
			docNumber: $this->requireText( $_POST['doc_number'] ?? '' ),
			inn:       $this->sanitizeText( $_POST['inn'] ?? '' ),
			email:     $this->sanitizeText( $_POST['email'] ?? '' ) ?: null,
		) );

		$this->relationshipService->replaceRepresentative( $oldRelId, $newGuardianId, $newType );

		$this->success();
	}

	/**
	 * AJAX: обновить данные лица (person).
	 *
	 * @return void
	 */
	public function ajaxUpdatePerson(): void {
		$this->authorize( Nonce::UpdatePerson, Capability::ManagePersons );

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );

		// Сбор изменяемых полей (только непустые)
		$changes = array_filter( array(
			'full_name'  => $this->sanitizeText( $_POST['full_name'] ?? '' ),
			'doc_number' => $this->sanitizeText( $_POST['doc_number'] ?? '' ),
			'inn'        => $this->sanitizeText( $_POST['inn'] ?? '' ),
			'address'    => $this->sanitizeText( $_POST['address'] ?? '' ),
			'phone'      => $this->sanitizeText( $_POST['phone'] ?? '' ),
			'email'      => $this->sanitizeText( $_POST['email'] ?? '' ),
		) );

		$this->personService->update( $personId, $changes, get_current_user_id() );

		$this->success();
	}

	/**
	 * AJAX: данные для вкладок модального окна (представители, подопечные, зачисления).
	 *
	 * @return void
	 */
	public function ajaxGetPersonData(): void {
		$this->authorize( Nonce::Manager, Capability::ManagePersons );

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );
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
			foreach ( $this->relationshipService->getActiveRepresentatives( $personId ) as $rel ) {
				$gPerson = $this->personRepository->find( $rel->guardianPersonId );
				$gUser   = $gPerson?->wpUserId ? get_userdata( $gPerson->wpUserId ) : null;
				$representatives[] = array(
					'id'                => $rel->id,
					'guardian_person_id' => $rel->guardianPersonId,
					'name'              => $gUser ? $gUser->display_name : "Person #{$rel->guardianPersonId}",
					'type_label'        => RelationType::tryFrom( $rel->relationType )?->label() ?? $rel->relationType,
					'since'             => substr( $rel->validFrom, 0, 10 ),
					'person_url'        => admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $rel->guardianPersonId ),
				);
			}
		}

		if ( $isParent ) {
			foreach ( $this->relationshipService->getActiveDependents( $personId ) as $rel ) {
				$sPerson = $this->personRepository->find( $rel->studentPersonId );
				$sUser   = $sPerson?->wpUserId ? get_userdata( $sPerson->wpUserId ) : null;
				$dependents[] = array(
					'student_person_id' => $rel->studentPersonId,
					'name'              => $sUser ? $sUser->display_name : "Person #{$rel->studentPersonId}",
					'type_label'        => RelationType::tryFrom( $rel->relationType )?->label() ?? $rel->relationType,
					'since'             => substr( $rel->validFrom, 0, 10 ),
					'person_url'        => admin_url( 'admin.php?page=fs-lms-person-detail&id=' . $rel->studentPersonId ),
				);
			}
		}

		$personIds   = $isStudent ? array( $personId ) : array_column( $dependents, 'student_person_id' );
		$enrollments = array();
		$nameMap     = array_column( $dependents, 'name', 'student_person_id' );

		foreach ( $personIds as $pid ) {
			foreach ( $this->enrollmentRepository->findByStudent( $pid ) as $enr ) {
				$group    = $this->groupRepository->getById( (string) $enr->groupId );
				$snapshot = array();
				if ( ! empty( $enr->snapshotEnc ) ) {
					try {
						$snapshot = json_decode( $this->crypto->decrypt( $enr->snapshotEnc ), true ) ?? array();
					} catch ( \Throwable $e ) {
						// snapshot недоступен
					}
				}
				$sd = $snapshot['student']  ?? array();
				$gd = $snapshot['guardian'] ?? array();

				$enrollments[] = array(
					'student_name'      => $isParent ? ( $nameMap[ $pid ] ?? "#{$pid}" ) : null,
					'subject_name'      => $this->subjectRepository->getByKey( $enr->subjectKey )?->name ?? $enr->subjectKey,
					'group_title'       => $group?->title ?? '—',
					'schedule'          => $this->formatSchedule( $group ),
					'period_key'        => $enr->periodKey,
					'status_label'      => $enr->status->label(),
					'status_value'      => $enr->status->value,
					'enrolled_at'       => substr( $enr->enrolledAt, 0, 10 ),
					'terminated_at'     => $enr->terminatedAt ? substr( $enr->terminatedAt, 0, 10 ) : null,
					'contract_no'         => $snapshot['contract_no']   ?? '',
					'student_phone'       => $sd['phone']      ?? '',
					'guardian_phone'      => $gd['phone']      ?? '',
					'school'              => $sd['school']     ?? '',
					'grade'               => isset( $sd['grade'] ) ? (string) $sd['grade'] : '',
					'birth_date'          => $sd['birth_date'] ?? '',
					'child_doc_number'    => $sd['doc_number'] ?? '',
					'child_inn'           => $sd['inn']        ?? '',
					'child_birth_date'    => $sd['birth_date'] ?? '',
					'guardian_birth_date' => $gd['birth_date'] ?? '',
				);
			}
		}

		$this->success( array(
			'type'            => $type,
			'wp_user_id'      => $person->wpUserId ?? 0,
			'display_name'    => $wpUser ? $wpUser->display_name : '',
			'login'           => $wpUser ? $wpUser->user_login : '',
			'email'           => $wpUser ? $wpUser->user_email : '',
			'masked_pii'      => $this->getMaskedPersonPii( $personId ),
			'representatives' => $representatives,
			'dependents'      => $dependents,
			'enrollments'     => $enrollments,
		) );
	}

	/**
	 * AJAX: раскрыть все PII-поля лица за одну операцию.
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
			$this->success( array(
				'doc_number' => $dto->pass,
				'inn'        => $dto->inn,
				'address'    => $dto->address,
				'phone'      => $dto->phone,
			) );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Возвращает маскированные PII-поля лица для отображения в модальном окне.
	 *
	 * @param int $personId ID лица
	 * @return array{doc_number: string, inn: string, address: string}
	 */
	private function getMaskedPersonPii( int $personId ): array {
		try {
			$dto = $this->personReader->readForDisplay(
				$personId,
				array( 'doc_number', 'inn', 'address', 'phone' ),
				'admin_masked_view'
			);
			return array(
				'doc_number' => $this->maskingService->mask( $dto->pass,    PiiField::Pass ),
				'inn'        => $this->maskingService->mask( $dto->inn,     PiiField::Inn ),
				'address'    => $this->maskingService->mask( $dto->address, PiiField::Address ),
				'password'   => $this->maskingService->mask( '',            PiiField::Password ),
			);
		} catch ( \Throwable ) {
			return array( 'doc_number' => '', 'inn' => '', 'address' => '', 'password' => '••••••••' );
		}
	}

	/**
	 * Конвертирует массив расписания группы в читаемую строку.
	 *
	 * @param mixed $group Объект группы или null
	 * @return string
	 */
	private function formatSchedule( mixed $group ): string {
		if ( null === $group ) {
			return '';
		}

		$schedule = $group->schedule ?? null;

		if ( empty( $schedule ) || ! is_array( $schedule ) ) {
			return '';
		}

		return WeekDay::formatSchedule( $schedule );
	}

	/**
	 * Данные для табов "Ученики" и "Родители" страницы "Пользователи".
	 * Вызывается из AdminCallbacks, не как отдельная страница.
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
	 * Страница карточки лица (person): ?page=fs-lms-person-detail&id=N
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

		// Расшифровка PII для отображения (если есть права)
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