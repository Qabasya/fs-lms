<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Managers\CronManager;

/**
 * Class CronController
 *
 * Контроллер WP Cron для LMS-событий.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация кастомных интервалов** — добавляет every_15_minutes через фильтр cron_schedules.
 * 2. **Регистрация cron-экшенов** — подключает callback-классы к хукам CronHook.
 *
 * ### Архитектурная роль:
 *
 * Единственное место регистрации add_filter/add_action для cron.
 * Делегирует данные об интервалах в CronManager. Callback-методы
 * будут добавлены по мере реализации соответствующих сервисов.
 */
class CronController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly CronManager $cron_manager,
	) {
		parent::__construct();
	}

	public function register(): void {
		$this->cron_manager->addCustomInterval( 'every_15_minutes', 900, 'Every 15 minutes' );
		add_filter( 'cron_schedules', array( $this->cron_manager, 'filterCronSchedules' ) );

		// Callback-хуки будут подключены по мере реализации:
		// add_action( CronHook::ExpireApplications->value, [ $application_callbacks, 'cronExpireApplications' ] );
		// add_action( CronHook::RetentionCleanup->value,   [ $retention_callbacks, 'cronRetentionCleanup' ] );
		// add_action( CronHook::RecoveryTick->value,       [ $recovery_callbacks, 'cronRecoveryTick' ] );
	}
}