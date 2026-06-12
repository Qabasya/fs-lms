<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Enrollment\RecoveryCallbacks;
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
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация cron-задач** — планирование фоновых задач через CronManager.
 * 2. **Подключение коллбеков** — связывание cron-хуков с методами RecoveryCallbacks.
 * 3. **Расширение TTL пароля** — увеличение срока действия ссылки установки пароля для LMS-ролей.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику RecoveryCallbacks, а управление cron — CronManager.
 * Реализует интерфейс ServiceInterface для единообразной инициализации.
 *
 * ### Cron-интервалы:
 *
 * - `every_15_minutes` — для восстановления зависших зачислений
 * - `daily` — для истечения заявок и очистки устаревших данных
 */
class RecoveryController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * @param RecoveryCallbacks $callbacks  Коллбеки для cron-задач
	 * @param CronManager       $cronManager Менеджер планирования cron
	 */
	public function __construct(
		private readonly RecoveryCallbacks $callbacks,
		private readonly CronManager       $cronManager,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'init' — хук для планирования cron-задач при загрузке WordPress
		add_action( 'init', array( $this, 'scheduleCronJobs' ) );

		// Регистрация cron-обработчиков (коллбеков)
		add_action( CronHook::RecoveryTick->value,       array( $this->callbacks, 'cronRecoveryTick' ) );
		add_action( CronHook::ExpireApplications->value, array( $this->callbacks, 'cronExpireApplications' ) );
		add_action( CronHook::RetentionCleanup->value,   array( $this->callbacks, 'cronRetentionCleanup' ) );

		// 'password_reset_expiration' — фильтр времени жизни ссылки сброса пароля
		add_filter( 'password_reset_expiration', array( $this, 'extendPasswordTtlForLmsUsers' ), 10, 2 );
	}

	/**
	 * Планирует cron-задачи через CronManager.
	 * Вызывается на хуке 'init'.
	 *
	 * @return void
	 */
	public function scheduleCronJobs(): void {
		// schedule() — метод CronManager для регистрации расписания
		// Параметры: хук, интервал
		$this->cronManager->schedule( CronHook::RecoveryTick->value,       'every_15_minutes' );
		$this->cronManager->schedule( CronHook::ExpireApplications->value, 'daily' );
		$this->cronManager->schedule( CronHook::RetentionCleanup->value,   'daily' );
	}

	/**
	 * Увеличивает TTL ссылки установки пароля до 48 часов для пользователей с LMS-ролями.
	 * Стандартный TTL в WordPress — 24 часа.
	 *
	 * @param int      $expiration Текущий TTL в секундах
	 * @param \WP_User $user       Объект пользователя WordPress
	 *
	 * @return int
	 */
	public function extendPasswordTtlForLmsUsers( int $expiration, \WP_User $user ): int {
		// Список LMS-ролей, для которых увеличиваем TTL
		$lmsRoles = array_map(
			static fn( UserRole $r ) => $r->value,
			array( UserRole::FSStudent, UserRole::FSParent, UserRole::FSTeacher, UserRole::FSOffice )
		);

		// array_intersect() — проверяет, есть ли у пользователя хотя бы одна LMS-роль
		if ( ! empty( array_intersect( $user->roles, $lmsRoles ) ) ) {
			// HOUR_IN_SECONDS — константа WordPress (3600 секунд)
			return 48 * HOUR_IN_SECONDS;  // 48 часов
		}

		return $expiration;  // Стандартное значение (24 часа)
	}
}