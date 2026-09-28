<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Managers\Wp\CronManager;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdReconcileService;

/**
 * Class AdSyncCronController
 *
 * Регулярная работа модуля: раз в минуту — ретраи и хвост очереди, раз в сутки —
 * сверка активных логинов с доменом. Хуки и интервал — константы модуля (ядро
 * о модуле не знает, core CronHook их не содержит).
 *
 * WP-cron срабатывает от посещений сайта: на хостинге лучше системный cron на
 * `wp-cron.php` раз в минуту (или `wp fs-lms ad flush`).
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdSyncCronController {

	public const string DELIVERY_HOOK  = 'fs_lms_ad_delivery_tick';
	public const string RECONCILE_HOOK = 'fs_lms_ad_reconcile_tick';

	private const string EVERY_MINUTE = 'fs_lms_every_minute';

	public function __construct(
		private readonly CronManager        $cron,
		private readonly AdDeliveryService  $delivery,
		private readonly AdReconcileService $reconcile,
		private readonly AdSyncConfig       $config,
	) {}

	public function register(): void {
		$this->cron->addCustomInterval( self::EVERY_MINUTE, MINUTE_IN_SECONDS, 'Every minute' );
		add_filter( 'cron_schedules', array( $this->cron, 'filterCronSchedules' ) );

		add_action( self::DELIVERY_HOOK, array( $this, 'onDeliveryTick' ) );
		add_action( self::RECONCILE_HOOK, array( $this, 'onReconcileTick' ) );

		$this->cron->schedule( self::DELIVERY_HOOK, self::EVERY_MINUTE );
		$this->cron->schedule( self::RECONCILE_HOOK, 'daily' );
	}

	public function onDeliveryTick(): void {
		$this->delivery->deliverPending();
	}

	public function onReconcileTick(): void {
		$this->delivery->reconcile( $this->reconcile->activeUsernames(), $this->config->reconcileApply() );
	}

	/** Модуль выключили — снять события, иначе WP-cron будет будить пустые хуки. */
	public function unschedule(): void {
		$this->cron->unschedule( self::DELIVERY_HOOK );
		$this->cron->unschedule( self::RECONCILE_HOOK );
	}
}
