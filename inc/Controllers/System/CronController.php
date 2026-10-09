<?php

declare( strict_types=1 );

namespace Inc\Controllers\System;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\CronHook;
use Inc\Managers\Wp\CronManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Exam\ExamTickLock;
use Inc\Services\Exam\ExamTickService;
use Inc\Services\Profile\AdminAlertCronService;
use Inc\Services\Profile\NotificationCronService;

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
 * 1. **Регистрация кастомных интервалов** — добавляет every_15_minutes и every_minute (экзамены: автоистечение и брони) через фильтр cron_schedules.
 * 2. **Регистрация cron-экшенов** — подключает callback-классы к хукам CronHook.
 *
 * ### Архитектурная роль:
 *
 * Единственное место регистрации add_filter/add_action для cron.
 * Делегирует данные об интервалах в CronManager. Callback-методы
 * будут добавлены по мере реализации соответствующих сервисов.
 */
class CronController extends BaseController implements ServiceInterface {

	/** Имя блокировки минутного тика экзаменов — общее с `wp fs-lms exam tick`. */
	public const EXAM_AUTO_EXPIRE_LOCK = 'exam_auto_expire';

	/** Имя блокировки тика броней гостей — общее с `wp fs-lms exam tick --name=hold-release`. */
	public const EXAM_HOLD_RELEASE_LOCK = 'exam_hold_release';

	/** Имя блокировки тика доставки событий outbox в ленту уведомлений. */
	public const EXAM_OUTBOX_LOCK = 'exam_outbox';

	public function __construct(
		private readonly CronManager                 $cron_manager,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly NotificationCronService     $notificationCron,
		private readonly AdminAlertCronService       $adminAlertCron,
		private readonly ExamTickLock                $examTickLock,
		private readonly ExamTickService             $examTicks,
	) {
		parent::__construct();
	}

	public function register(): void {
		$this->cron_manager->addCustomInterval( 'every_15_minutes', 900, 'Every 15 minutes' );
		$this->cron_manager->addCustomInterval( 'every_minute', 60, 'Every minute' );
		add_filter( 'cron_schedules', array( $this->cron_manager, 'filterCronSchedules' ) );

		add_action( CronHook::ExpireAttempts->value, array( $this, 'handleExpireAttempts' ) );

		if ( ! wp_next_scheduled( CronHook::ExpireAttempts->value ) ) {
			wp_schedule_event( time(), 'hourly', CronHook::ExpireAttempts->value );
		}

		add_action( CronHook::NotificationsTick->value, array( $this, 'handleNotificationsTick' ) );
		$this->cron_manager->schedule( CronHook::NotificationsTick->value, 'every_15_minutes' );

		// Экзамены: минутный тик (автоистечение попыток + неявки). Расписание NotificationsTick не меняется (README §8, п. 2).
		add_action( CronHook::ExamAutoExpireTick->value, array( $this, 'handleExamAutoExpireTick' ) );
		$this->cron_manager->schedule( CronHook::ExamAutoExpireTick->value, 'every_minute' );
		add_action( CronHook::ExamHoldReleaseTick->value, array( $this, 'handleExamHoldReleaseTick' ) );
		$this->cron_manager->schedule( CronHook::ExamHoldReleaseTick->value, 'every_minute' );
		add_action( CronHook::ExamOutboxTick->value, array( $this, 'handleExamOutboxTick' ) );
		$this->cron_manager->schedule( CronHook::ExamOutboxTick->value, 'every_minute' );

		// ExpireApplications / RetentionCleanup / RecoveryTick подключает
		// RecoveryController (их расписание ставит Activate) — здесь не дублируем.
	}

	public function handleExpireAttempts(): void {
		$this->attemptRepo->expireOverdue();
	}

	/** Только делегирование: тело — {@see ExamTickService}, защита от параллельного запуска — {@see ExamTickLock}. */
	public function handleExamAutoExpireTick(): void {
		$this->examTickLock->run( self::EXAM_AUTO_EXPIRE_LOCK, fn() => $this->examTicks->autoExpireTick() );
	}

	/** Освобождение истёкших броней гостей — тоже только делегирование под своей блокировкой. */
	public function handleExamHoldReleaseTick(): void {
		$this->examTickLock->run( self::EXAM_HOLD_RELEASE_LOCK, fn() => $this->examTicks->releaseHolds() );
	}

	/** Доставка событий outbox в ленту уведомлений — делегирование под своей блокировкой. */
	public function handleExamOutboxTick(): void {
		$this->examTickLock->run( self::EXAM_OUTBOX_LOCK, fn() => $this->examTicks->deliverEvents() );
	}

	public function handleNotificationsTick(): void {
		$this->notificationCron->tick();
		$this->adminAlertCron->tick();
	}
}