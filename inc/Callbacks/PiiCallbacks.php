<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Services\AuditService;
use Inc\Services\EmailService;
use Inc\Services\PasswordLinkService;
use Inc\Services\PersonReader;
use Inc\Services\PersonService;
use Inc\Services\PiiExportService;
use Inc\Services\RateLimitService;
use Inc\Services\RelationshipService;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PiiCallbacks
 *
 * AJAX-коллбеки и страницы adminки для работы с персональными данными.
 *
 * @package Inc\Callbacks
 */
class PiiCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly PersonReader        $personReader,
		private readonly PersonService       $personService,
		private readonly PersonRepository    $personRepository,
		private readonly RelationshipService $relationshipService,
		private readonly RateLimitService    $rateLimitService,
		private readonly PiiExportService    $piiExportService,
		private readonly PasswordLinkService $passwordLinkService,
		private readonly EmailService        $emailService,
		private readonly AuditService        $auditService,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: раскрыть одно PII-поле на 30 секунд.
	 */
	public function ajaxRevealPiiField(): void {
		check_ajax_referer( Nonce::RevealPii->value, 'security' );

		if ( ! current_user_can( Capability::ViewPII->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		if ( ! $this->rateLimitService->allowPiiReveal( get_current_user_id() ) ) {
			$this->error( 'Лимит раскрытий превышен.', 429 );
		}

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );
		$field    = $this->sanitizeText( $_POST['field'] ?? '' );
		$reason   = $this->sanitizeText( $_POST['reason'] ?? 'admin_reveal' );

		$value = $this->personReader->readField( $personId, $field, $reason );

		$this->success( array( 'value' => $value ) );
	}

	/**
	 * AJAX: запросить удаление ПД (soft delete).
	 */
	public function ajaxRequestPiiDeletion(): void {
		check_ajax_referer( Nonce::RequestPiiDeletion->value, 'security' );

		if ( ! current_user_can( Capability::ManagePersons->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );

		$this->personService->softDelete( $personId, get_current_user_id() );

		$this->success();
	}

	/**
	 * AJAX: создать файл экспорта ПД и вернуть одноразовую ссылку.
	 */
	public function ajaxExportPii(): void {
		check_ajax_referer( Nonce::ExportPii->value, 'security' );

		if ( ! current_user_can( Capability::ExportPII->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );
		$actorId  = get_current_user_id();

		$payload  = $this->piiExportService->buildExport( $personId, $actorId );
		$link     = $this->piiExportService->createDownloadLink( $payload );

		$this->success( array( 'download_url' => $link ) );
	}

	/**
	 * AJAX: добавить нового представителя к ученику.
	 */
	public function ajaxAddRepresentative(): void {
		check_ajax_referer( Nonce::AddRepresentative->value, 'security' );

		if ( ! current_user_can( Capability::ManagePersons->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$studentPersonId  = $this->sanitizeInt( $_POST['student_person_id'] ?? 0 );
		$relationType     = \Inc\Enums\RelationType::from( $this->requireKey( $_POST['relation_type'] ?? '' ) );
		$isPrimary        = ! empty( $_POST['is_primary'] );

		$guardianPersonId = $this->personService->createOrFindBy( array(
			'full_name'  => $this->requireText( $_POST['full_name'] ?? '' ),
			'doc_number' => $this->requireText( $_POST['doc_number'] ?? '' ),
			'inn'        => $this->sanitizeText( $_POST['inn'] ?? '' ),
			'snils'      => $this->sanitizeText( $_POST['snils'] ?? '' ),
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
	 */
	public function ajaxReplaceRepresentative(): void {
		check_ajax_referer( Nonce::ReplaceRepresentative->value, 'security' );

		if ( ! current_user_can( Capability::ManagePersons->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$oldRelId      = $this->sanitizeInt( $_POST['relationship_id'] ?? 0 );
		$newType       = \Inc\Enums\RelationType::from( $this->requireKey( $_POST['relation_type'] ?? '' ) );

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
	 * AJAX: обновить данные person.
	 */
	public function ajaxUpdatePerson(): void {
		check_ajax_referer( Nonce::UpdatePerson->value, 'security' );

		if ( ! current_user_can( Capability::ManagePersons->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$personId = $this->sanitizeInt( $_POST['person_id'] ?? 0 );

		$changes = array_filter( array(
			'full_name'  => $this->sanitizeText( $_POST['full_name'] ?? '' ),
			'doc_number' => $this->sanitizeText( $_POST['doc_number'] ?? '' ),
			'inn'        => $this->sanitizeText( $_POST['inn'] ?? '' ),
			'snils'      => $this->sanitizeText( $_POST['snils'] ?? '' ),
			'address'    => $this->sanitizeText( $_POST['address'] ?? '' ),
			'phone'      => $this->sanitizeText( $_POST['phone'] ?? '' ),
			'email'      => $this->sanitizeText( $_POST['email'] ?? '' ),
		) );

		$this->personService->update( $personId, $changes, get_current_user_id() );

		$this->success();
	}

	/**
	 * Страница списка persons: /wp-admin/admin.php?page=fs-lms-persons
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
	 * Страница карточки person: ?page=fs-lms-person-detail&id=N
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
			? $this->personReader->readForDisplay( $personId, array( 'full_name', 'doc_number', 'inn', 'snils', 'address', 'phone' ), 'admin_view' )
			: null;

		$template = $this->path( 'templates/admin/enrollment/person-detail.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Person #' . esc_html( (string) $personId ) . '</h1><p>Шаблон не найден.</p></div>';
		}
	}
}