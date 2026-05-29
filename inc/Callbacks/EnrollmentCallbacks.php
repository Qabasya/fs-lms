<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\AuditService;
use Inc\Services\EnrollmentService;
use Inc\Services\PiiCryptoService;
use Inc\Services\PiiMaskingService;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\DTO\EnrollmentInputDTO;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class EnrollmentCallbacks
 *
 * Callbacks для страниц заявок и операций зачисления в adminке.
 *
 * @package Inc\Callbacks
 */
class EnrollmentCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly ApplicationRepository  $applicationRepository,
		private readonly EnrollmentService      $enrollmentService,
		private readonly AuditService           $auditService,
		private readonly PiiCryptoService       $crypto,
		private readonly PiiMaskingService      $piiMasking,
		private readonly PiiAccessLogRepository $piiAccessLog,
	) {
		parent::__construct();
	}

	/**
	 * Страница списка заявок: /wp-admin/admin.php?page=fs-lms-applications
	 */
	public function renderApplicationsListPage(): void {
		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			wp_die( 'Доступ запрещён.' );
		}

		$status   = sanitize_key( $_GET['status'] ?? '' );
		$dateFrom = sanitize_text_field( $_GET['date_from'] ?? '' );
		$dateTo   = sanitize_text_field( $_GET['date_to'] ?? '' );
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$filters = array_filter( array(
			'status'    => $status ?: null,
			'date_from' => $dateFrom ?: null,
			'date_to'   => $dateTo ?: null,
		) );

		$perPage = 20;
		$apps    = $this->applicationRepository->list( $filters, $page, $perPage );
		$total   = $this->applicationRepository->count( $filters );

		$template = $this->path( 'templates/admin/enrollment/applications-list.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Заявки</h1><p>Шаблон не найден.</p></div>';
		}
	}

	/**
	 * Страница карточки заявки: ?page=fs-lms-application-detail&id=N
	 */
	public function renderApplicationDetailPage(): void {
		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			wp_die( 'Доступ запрещён.' );
		}

		$id  = (int) ( $_GET['id'] ?? 0 );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			wp_die( 'Заявка не найдена.' );
		}

		$studentData = null;
		$parentData  = null;

		if ( ! empty( $app->student_data_enc ) && current_user_can( Capability::ViewPII->value ) ) {
			try {
				$studentData = json_decode( $this->crypto->decrypt( $app->student_data_enc ), true );
			} catch ( \Throwable $e ) {
				$studentData = null;
			}

			$this->piiAccessLog->create( array(
				'actor_user_id'  => get_current_user_id(),
				'actor_role'     => 'admin',
				'person_id'      => null,
				'fields_accessed' => 'student_data',
				'access_reason'  => 'application_review',
				'actor_ip'       => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'created_at'     => current_time( 'mysql', true ),
			) );
		}

		if ( ! empty( $app->parent_data_enc ) && current_user_can( Capability::ViewPII->value ) ) {
			try {
				$parentData = json_decode( $this->crypto->decrypt( $app->parent_data_enc ), true );
			} catch ( \Throwable $e ) {
				$parentData = null;
			}
		}

		$this->auditService->record(
			AuditAction::ViewApplication->value,
			'application',
			$id
		);

		$template = $this->path( 'templates/admin/enrollment/application-detail.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Заявка #' . esc_html( (string) $id ) . '</h1><p>Шаблон не найден.</p></div>';
		}
	}

	/**
	 * AJAX: зачислить студента.
	 */
	public function ajaxEnrollStudent(): void {
		check_ajax_referer( Nonce::Enroll->value, 'security' );

		if ( ! current_user_can( Capability::EnrollStudent->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$dto = new EnrollmentInputDTO(
			applicationId: $this->sanitizeInt( $_POST['application_id'] ?? 0 ),
			contractNo:    $this->requireText( $_POST['contract_no'] ?? '' ),
			contractDate:  $this->requireText( $_POST['contract_date'] ?? '' ),
			orderNo:       $this->requireText( $_POST['order_no'] ?? '' ),
			orderDate:     $this->requireText( $_POST['order_date'] ?? '' ),
			enrolledAt:    $this->requireText( $_POST['enrolled_at'] ?? '' ),
			subjectKey:    $this->requireKey( $_POST['subject_key'] ?? '' ),
			groupId:       $this->sanitizeInt( $_POST['group_id'] ?? 0 ),
			periodKey:     $this->requireKey( $_POST['period_key'] ?? '' ),
			sendEmailAuto: ! empty( $_POST['send_email_auto'] ),
		);

		try {
			$result = $this->enrollmentService->enroll( $dto );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}

		if ( $result->partialFailure ) {
			$this->success( array(
				'partial'       => true,
				'enrollment_id' => $result->enrollmentId,
				'message'       => 'Зачисление выполнено. Учётные записи будут созданы автоматически в течение 15 минут. Enrollment ID: ' . $result->enrollmentId,
			) );
		}

		$response = array( 'enrollment_id' => $result->enrollmentId );

		if ( null !== $result->guardianPasswordLink ) {
			$response['guardian_link'] = $result->guardianPasswordLink;
			$response['student_link']  = $result->studentPasswordLink;
			$response['message']       = 'Зачисление выполнено. Передайте ссылки представителю.';
		} else {
			$response['message'] = 'Зачисление выполнено. Ссылки для установки пароля отправлены на email.';
		}

		$this->success( $response );
	}

	/**
	 * AJAX: отклонить заявку.
	 */
	public function ajaxRejectApplication(): void {
		check_ajax_referer( Nonce::Reject->value, 'security' );

		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$id     = $this->sanitizeInt( $_POST['application_id'] ?? 0 );
		$reason = $this->sanitizeText( $_POST['reason'] ?? '' );

		$this->applicationRepository->update( $id, array(
			'status'               => ApplicationStatus::Rejected->value,
			'rejected_reason'      => $reason,
			'reviewed_by_user_id'  => get_current_user_id(),
			'reviewed_at'          => current_time( 'mysql', true ),
			'updated_at'           => current_time( 'mysql', true ),
		) );

		$this->auditService->record(
			AuditAction::RejectApplication->value,
			'application',
			$id,
			array( 'reason' => $reason )
		);

		$this->success();
	}

	/**
	 * AJAX: переместить заявку в корзину.
	 */
	public function ajaxMoveApplicationToTrash(): void {
		check_ajax_referer( Nonce::TrashApplication->value, 'security' );

		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$id = $this->sanitizeInt( $_POST['application_id'] ?? 0 );

		$this->applicationRepository->setStatus( $id, ApplicationStatus::Trash );

		$this->auditService->record(
			AuditAction::MoveToTrash->value,
			'application',
			$id
		);

		$this->success();
	}

	/**
	 * AJAX: восстановить заявку из корзины.
	 */
	public function ajaxRestoreApplicationFromTrash(): void {
		check_ajax_referer( Nonce::TrashApplication->value, 'security' );

		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$id  = $this->sanitizeInt( $_POST['application_id'] ?? 0 );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			$this->error( 'Заявка не найдена.' );
		}

		$target = ! empty( $app->parent_data_enc )
			? ApplicationStatus::ReadyForReview
			: ApplicationStatus::PendingParent;

		$this->applicationRepository->setStatus( $id, $target );

		$this->auditService->record(
			AuditAction::RestoreFromTrash->value,
			'application',
			$id
		);

		$this->success();
	}

	/**
	 * AJAX: очистить корзину (физически удалить все trash-заявки).
	 */
	public function ajaxEmptyApplicationsTrash(): void {
		check_ajax_referer( Nonce::TrashApplication->value, 'security' );

		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			$this->error( 'Доступ запрещён.' );
		}

		$trashApps = $this->applicationRepository->list(
			array( 'status' => ApplicationStatus::Trash->value ),
			1,
			9999
		);

		$count = 0;

		foreach ( $trashApps as $app ) {
			try {
				$this->applicationRepository->delete( $app->id );
				$count++;
			} catch ( \Throwable $e ) {
				// Логируем, но не останавливаем цикл
				error_log( '[FS LMS] EmptyTrash: не удалось удалить заявку #' . $app->id . ': ' . $e->getMessage() );
			}
		}

		$this->auditService->record(
			AuditAction::EmptyTrash->value,
			'application',
			null,
			array( 'deleted_count' => $count )
		);

		$this->success( array( 'deleted' => $count ) );
	}
}