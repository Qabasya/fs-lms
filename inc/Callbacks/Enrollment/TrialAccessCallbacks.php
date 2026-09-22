<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Enrollment\TrialAccessService;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TrialAccessCallbacks
 *
 * Временный доступ ученика к группе до зачисления: выдача и снятие из таблицы заявок,
 * плюс снятие при истечении заявки и переносе в корзину (хуки жизненного цикла заявки).
 *
 * @package Inc\Callbacks\Enrollment
 */
class TrialAccessCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TrialAccessService $trialAccess,
	) {
		parent::__construct();
	}

	/**
	 * AJAX: выдать временный доступ. Params: application_id, group_id.
	 */
	public function ajaxGrantTrialAccess(): void {
		$this->authorize( Nonce::Enroll, Capability::EnrollStudent );

		$applicationId = $this->requireInt( 'application_id', error: 'Не указан ID заявки.' );
		$groupId       = $this->requireInt( 'group_id', error: 'Выберите группу.' );

		try {
			$result = $this->trialAccess->grant( $applicationId, $groupId );
		} catch ( \InvalidArgumentException | \DomainException | \RuntimeException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$data = $result->toArray();
		// Подпись для уведомления: срок хранится в UTC.
		$data['expires_at_local'] = '' !== $result->expiresAt ? get_date_from_gmt( $result->expiresAt, 'j F' ) : '';

		$this->success( $data );
	}

	/**
	 * AJAX: снять временный доступ. Params: application_id.
	 */
	public function ajaxRevokeTrialAccess(): void {
		$this->authorize( Nonce::Enroll, Capability::EnrollStudent );

		$applicationId = $this->requireInt( 'application_id', error: 'Не указан ID заявки.' );

		if ( ! $this->trialAccess->revoke( $applicationId, get_current_user_id() ) ) {
			$this->error( 'У этой заявки нет временного доступа.' );
			return;
		}

		$this->success();
	}

	/**
	 * Хуки `fs_lms_application_expired` / `fs_lms_application_trashed`: доступ живёт
	 * не дольше заявки. Сбой снятия не должен ронять cron истечения или перенос в корзину.
	 *
	 * @param int $applicationId ID заявки
	 */
	public function onApplicationClosed( int $applicationId ): void {
		try {
			$this->trialAccess->revoke( $applicationId, get_current_user_id() );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'TrialAccessRevoke', $e, array( 'application_id' => $applicationId ), true );
		}
	}
}
