<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\Enrollment\EnrollmentInputDTO;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Access\Capability;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Enrollment\EnrollmentService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class EnrollmentLifecycleCallbacks
 *
 * Жизненный цикл зачисления: старт (ReadyForReview → Enrolling), само зачисление,
 * отмена, восстановление из архива + справочник групп для формы зачисления.
 *
 * Выделен из EnrollmentCallbacks (Т14.2); корзина заявок — ApplicationTrashCallbacks,
 * правка/чтение данных — ApplicationDataCallbacks, родители — ParentLinkCallbacks,
 * креды — UserCredentialsCallbacks.
 *
 * @package Inc\Callbacks\Enrollment
 */
class EnrollmentLifecycleCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ApplicationRepository       $applicationRepository,
		private readonly EnrollmentService           $enrollmentService,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly GroupsRepository            $studentGroupRepository,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: зачисление студента.
	 *
	 * @return void
	 */
	public function ajaxEnrollStudent(): void {
		$this->authorize( Nonce::Enroll, Capability::EnrollStudent );

		$applicationId = $this->sanitizeInt( 'application_id' );

		try {
			$dto = new EnrollmentInputDTO(
				applicationId: $applicationId,
				contractNo:    $this->sanitizeText( 'contract_no' ),
				contractDate:  $this->requireText( 'contract_date' ),
				orderNo:       $this->requireText( 'order_no' ),
				orderDate:     $this->requireText( 'order_date' ),
				enrolledAt:    $this->requireText( 'enrolled_at' ),
				groupId:       $this->sanitizeInt( 'group_id' ),
				sendEmailAuto: true,
			);

			$result = $this->enrollmentService->enroll( $dto );
		} catch ( \Throwable $e ) {
			// Транзакция откатилась или DTO не собрался — возвращаем заявку в ready_for_review
			$this->applicationRepository->update( $applicationId, array(
				'status'     => ApplicationStatus::ReadyForReview->value,
				'updated_at' => current_time( 'mysql', true ),
			) );
			$this->error( $e->getMessage() );
		}

		if ( $result->partialFailure ) {
			$this->success( array(
				'partial'       => true,
				'enrollment_id' => $result->enrollmentId,
				'message'       => 'Зачисление выполнено, но учётные записи не созданы. Требуется ручное исправление. Причина: ' . ( $result->errorMessage ?? 'неизвестно' ),
			) );
		}

		$this->success( array(
			'enrollment_id'    => $result->enrollmentId,
			'student_login'    => $result->studentLogin,
			'student_password' => $result->studentPassword,
			'guardian_login'   => $result->guardianLogin,
			'guardian_password' => $result->guardianPassword,
			'message'          => 'Зачисление выполнено.',
		) );
	}

	/**
	 * AJAX: перевод заявки из ReadyForReview в Enrolling.
	 *
	 * @return void
	 */
	public function ajaxStartEnrollment(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::ReadyForReview ) {
			$this->error( 'Заявка не найдена или не в статусе "Готова к зачислению".' );
		}

		$this->applicationRepository->update( $id, array(
			'status'     => ApplicationStatus::Enrolling->value,
			'updated_at' => current_time( 'mysql', true ),
		) );

		$this->logEvents->dispatch( LogEvent::EnrollmentStarted, new ApplicationStatusEvent(
			get_current_user_id(), AuditAction::StartEnrollment, $id
		) );

		$this->success();
	}

	/**
	 * AJAX: отмена зачисления (Enrolling → ReadyForReview).
	 *
	 * @return void
	 */
	public function ajaxCancelEnrollment(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::Enrolling ) {
			$this->success();
			return;
		}

		$this->applicationRepository->update( $id, array(
			'status'     => ApplicationStatus::ReadyForReview->value,
			'updated_at' => current_time( 'mysql', true ),
		) );

		$this->success();
	}

	/**
	 * AJAX: восстановление ученика из архива — создание новой заявки (события 2A, 4B).
	 */
	public function ajaxRestoreFromArchive(): void {
		$this->authorize( Nonce::RestoreFromArchive, Capability::ManageApplications );

		$archiveId  = $this->requireInt( 'archive_id', error: 'Не указан ID архивной записи.' );
		$withParent = (bool) $this->sanitizeInt( 'with_parent' );

		try {
			$result = $this->enrollmentService->restoreFromArchive( $archiveId, $withParent );
			$this->success( $result->toArray() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: массовое восстановление учеников из архива — одно решение (с родителем/без)
	 * применяется ко всем выбранным записям, для каждой создаётся отдельная заявка.
	 */
	public function ajaxBulkRestoreFromArchive(): void {
		$this->authorize( Nonce::RestoreFromArchive, Capability::ManageApplications );

		$ids = array_filter( $this->sanitizeIntList( 'ids' ) );
		if ( ! $ids ) {
			$this->error( 'Не выбрано ни одной записи.' );
		}

		$withParent = (bool) $this->sanitizeInt( 'with_parent' );

		$created = array();
		$errors  = array();

		foreach ( $ids as $archiveId ) {
			try {
				$result    = $this->enrollmentService->restoreFromArchive( $archiveId, $withParent );
				$created[] = $result->toArray();
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'archive_id' => $archiveId,
					'message'    => $e->getMessage(),
				);
			}
		}

		$this->success( array(
			'created' => $created,
			'errors'  => $errors,
		) );
	}

	/**
	 * AJAX: список групп по периоду и предмету.
	 *
	 * @return void
	 */
	public function ajaxGetStudentGroups(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$periodId   = $this->sanitizeText( 'period_id' );
		$subjectKey = $this->sanitizeText( 'subject_id' );

		$groups = $this->studentGroupRepository->findByPeriodAndSubject( $periodId, $subjectKey );

		$result = array_values( array_map(
			static fn( object $g ) => array( 'id' => (int) $g->id, 'title' => $g->name ),
			$groups
		) );

		$this->success( $result );
	}
}
