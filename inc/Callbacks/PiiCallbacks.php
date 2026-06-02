<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Enums\RelationType;
use Inc\Enums\UserRole;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Services\EmailService;
use Inc\Services\Person\PersonReader;
use Inc\Services\Person\PersonService;
use Inc\Services\Person\PiiExportService;
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
 * 4. **Экспорт PII** — создание одноразовой ссылки для экспорта персональных данных.
 * 5. **Отображение страниц** — рендеринг списков лиц и детальных карточек.
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
	 * @param PiiExportService    $piiExportService    Сервис экспорта PII
	 * @param EmailService        $emailService        Сервис отправки email
	 * @param AuditService        $auditService        Сервис аудита
	 */
	public function __construct(
		private readonly PersonReader           $personReader,
		private readonly PersonService          $personService,
		private readonly PersonRepository       $personRepository,
		private readonly RelationshipService    $relationshipService,
		private readonly RateLimitService       $rateLimitService,
		private readonly PiiExportService       $piiExportService,
		private readonly EmailService           $emailService,
		private readonly AuditService           $auditService,
		private readonly EnrollmentRepository   $enrollmentRepository,
		private readonly StudentGroupRepository $groupRepository,
		private readonly SubjectRepository      $subjectRepository,
		private readonly PiiCryptoService       $crypto,
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

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );
		$field    = $this->sanitizeText( $_POST['field'] ?? '' );
		$reason   = $this->sanitizeText( $_POST['reason'] ?? 'admin_reveal' );

		try {
			$value = $this->personReader->readField( $personId, $field, $reason );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}

		$this->success( array( 'value' => $value ) );
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
	 * AJAX: создать файл экспорта ПД и вернуть одноразовую ссылку.
	 *
	 * @return void
	 */
	public function ajaxExportPii(): void {
		$this->authorize( Nonce::ExportPii, Capability::ExportPII );

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );
		$actorId  = get_current_user_id();

		// Формирование JSON-данных для экспорта
		$payload = $this->piiExportService->buildExport( $personId, $actorId );
		// Генерация одноразовой ссылки на скачивание
		$link = $this->piiExportService->createDownloadLink( $payload );

		$this->success( array( 'download_url' => $link ) );
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
		$guardianPersonId = $this->personService->createOrFindBy( array(
			'full_name'  => $this->requireText( $_POST['full_name'] ?? '' ),
			'doc_number' => $this->requireText( $_POST['doc_number'] ?? '' ),
			'inn'        => $this->sanitizeText( $_POST['inn'] ?? '' ),
			'address'    => $this->sanitizeText( $_POST['address'] ?? '' ),
			'phone'      => $this->sanitizeText( $_POST['phone'] ?? '' ),
			'email'      => $this->sanitizeText( $_POST['email'] ?? '' ),
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

		$newGuardianId = $this->personService->createOrFindBy( array(
			'full_name'  => $this->requireText( $_POST['full_name'] ?? '' ),
			'doc_number' => $this->requireText( $_POST['doc_number'] ?? '' ),
			'inn'        => $this->sanitizeText( $_POST['inn'] ?? '' ),
			'email'      => $this->sanitizeText( $_POST['email'] ?? '' ),
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
			'representatives' => $representatives,
			'dependents'      => $dependents,
			'enrollments'     => $enrollments,
		) );
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