<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Access\Capability;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Application\ApplicationService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ApplicationTrashCallbacks
 *
 * Корзина заявок: перемещение, восстановление, точечное удаление и полная очистка.
 *
 * Выделен из EnrollmentCallbacks (Т14.2).
 *
 * @package Inc\Callbacks\Enrollment
 */
class ApplicationTrashCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ApplicationRepository       $applicationRepository,
		private readonly ApplicationService          $applications,
		private readonly LogEventDispatcherInterface $logEvents,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: перемещение заявки в корзину.
	 *
	 * @return void
	 */
	public function ajaxMoveApplicationToTrash(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		$id = $this->sanitizeInt( 'application_id' );

		$this->applications->changeStatus( $id, ApplicationStatus::Trash );

		$this->logEvents->dispatch( LogEvent::ApplicationTrashed, new ApplicationStatusEvent(
			get_current_user_id(), AuditAction::MoveToTrash, $id
		) );

		// Generic-сейм для опциональных модулей (напр. AdSync deprovision). Без подписчиков — no-op.
		// Фитим на trash (заявка ещё существует), не на EmptyTrash (там запись удаляется).
		do_action( 'fs_lms_application_trashed', $id );

		$this->success();
	}

	/**
	 * AJAX: восстановление заявки из корзины.
	 *
	 * @return void
	 */
	public function ajaxRestoreApplicationFromTrash(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			$this->error( 'Заявка не найдена.' );
		}

		// Определение целевого статуса: ReadyForReview (заполнена родителем) или PendingParent
		$target = ! empty( $app->parentDataEnc )
			? ApplicationStatus::ReadyForReview
			: ApplicationStatus::PendingParent;

		$this->applications->changeStatus( $id, $target );

		$this->logEvents->dispatch( LogEvent::ApplicationRestored, new ApplicationStatusEvent(
			get_current_user_id(), AuditAction::RestoreFromTrash, $id
		) );

		$this->success();
	}

	/**
	 * AJAX: постоянное удаление одной заявки (только из корзины).
	 *
	 * @return void
	 */
	public function ajaxDeleteApplication(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::Trash ) {
			$this->error( 'Заявка не найдена или не в корзине.' );
		}

		$this->applications->deleteFromTrash( $id );

		$this->logEvents->dispatch( LogEvent::ApplicationTrashed, new ApplicationStatusEvent(
			get_current_user_id(), AuditAction::EmptyTrash, $id
		) );

		$this->success();
	}

	/**
	 * AJAX: очистка корзины (физическое удаление всех заявок со статусом Trash).
	 *
	 * @return void
	 */
	public function ajaxEmptyApplicationsTrash(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		// Получение всех заявок в корзине
		$trashApps = $this->applicationRepository->list(
			array( 'status' => ApplicationStatus::Trash->value ),
			1,
			9999
		);

		$count = 0;

		$actor = get_current_user_id();

		foreach ( $trashApps as $app ) {
			try {
				// Физическое удаление записи из БД
				$this->applications->deleteFromTrash( $app->id );
				$this->logEvents->dispatch( LogEvent::ApplicationTrashed, new ApplicationStatusEvent(
					$actor, AuditAction::EmptyTrash, $app->id
				) );
				$count++;
			} catch ( \Throwable $e ) {
				// Логируем, но не останавливаем цикл
				trigger_error( '[FS LMS] EmptyTrash: не удалось удалить заявку #' . $app->id . ': ' . $e->getMessage(), E_USER_WARNING );
			}
		}

		$this->success( array( 'deleted' => $count ) );
	}
}
