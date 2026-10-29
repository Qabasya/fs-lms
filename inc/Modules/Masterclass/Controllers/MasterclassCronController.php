<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Managers\Wp\CronManager;
use Inc\Modules\Masterclass\Services\MasterclassLifecycleService;
use Inc\Services\Exam\ExamTickLock;

/**
 * Class MasterclassCronController
 *
 * Тик модуля раз в 15 минут (хук — константа модуля, core `CronHook` о нём не знает). Тело тика — под замком
 * `ExamTickLock`: два тика одновременно не работают, исключение тела поглощается замком и пишется в журнал ошибок,
 * крон не падает. Регистрируется только при включённом модуле; выключение снимает событие
 * (`fs_lms_module_toggle_masterclass` → {@see self::unschedule()}).
 *
 * WP-cron срабатывает от посещений сайта: на хостинге лучше системный cron на `wp-cron.php`. Ручной запуск —
 * `wp cron event run fs_lms_masterclass_tick`.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassCronController {

	public const TICK_HOOK = 'fs_lms_masterclass_tick';

	/** Имя замка тика (часть имени блокировки в `ExamTickLock`). */
	public const LOCK = 'masterclass_tick';

	private const INTERVAL = 'every_15_minutes';

	public function __construct(
		private readonly CronManager $cron,
		private readonly ExamTickLock $lock,
		private readonly MasterclassLifecycleService $lifecycle,
	) {}

	public function register(): void {
		// Интервал регистрирует и ядро; повтор той же записи безвреден, зато первый запрос после включения не упустит расписание.
		$this->cron->addCustomInterval( self::INTERVAL, 900, 'Every 15 minutes' );
		add_filter( 'cron_schedules', array( $this->cron, 'filterCronSchedules' ) );

		add_action( self::TICK_HOOK, array( $this, 'onTick' ) );
		$this->cron->schedule( self::TICK_HOOK, self::INTERVAL );
	}

	public function onTick(): void {
		$this->lock->run( self::LOCK, fn () => $this->lifecycle->tick() );
	}

	/** Модуль выключили — снять событие, иначе WP-cron будет будить пустой хук. */
	public function unschedule(): void {
		$this->cron->unschedule( self::TICK_HOOK );
	}
}
