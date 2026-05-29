<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\RecoveryCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\CronHook;
use Inc\Enums\UserRole;
use Inc\Managers\CronManager;

/**
 * Class RecoveryController
 *
 * Регистрирует cron-задачи recovery/retention и фильтр TTL ссылки установки пароля.
 *
 * @package Inc\Controllers
 */
class RecoveryController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly RecoveryCallbacks $callbacks,
		private readonly CronManager       $cronManager,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'scheduleCronJobs' ) );

		add_action( CronHook::RecoveryTick->value,       array( $this->callbacks, 'cronRecoveryTick' ) );
		add_action( CronHook::ExpireApplications->value, array( $this->callbacks, 'cronExpireApplications' ) );
		add_action( CronHook::RetentionCleanup->value,   array( $this->callbacks, 'cronRetentionCleanup' ) );

		add_filter( 'password_reset_expiration', array( $this, 'extendPasswordTtlForLmsUsers' ), 10, 2 );
	}

	public function scheduleCronJobs(): void {
		$this->cronManager->schedule( CronHook::RecoveryTick->value,       'every_15_minutes' );
		$this->cronManager->schedule( CronHook::ExpireApplications->value, 'daily' );
		$this->cronManager->schedule( CronHook::RetentionCleanup->value,   'daily' );
	}

	/**
	 * Увеличивает TTL ссылки установки пароля до 48 часов для LMS-ролей.
	 *
	 * @param int      $expiration Текущий TTL в секундах
	 * @param \WP_User $user       Пользователь
	 *
	 * @return int
	 */
	public function extendPasswordTtlForLmsUsers( int $expiration, \WP_User $user ): int {
		$lmsRoles = array_map(
			static fn( UserRole $r ) => $r->value,
			array( UserRole::FSStudent, UserRole::FSParent, UserRole::FSTeacher, UserRole::FSOffice )
		);

		if ( ! empty( array_intersect( $user->roles, $lmsRoles ) ) ) {
			return 48 * HOUR_IN_SECONDS;
		}

		return $expiration;
	}
}