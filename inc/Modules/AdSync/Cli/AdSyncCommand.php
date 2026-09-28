<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Cli;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdReconcileService;
use WP_CLI;

/**
 * Class AdSyncCommand
 *
 * `wp fs-lms ad …` — ручное управление доставкой в офис. `flush` годится и для
 * системного cron хостинга вместо WP-cron (тот срабатывает только от посещений).
 * Регистрируется модулем, только под WP-CLI (модуль self-contained, вне core `inc/Cli`).
 *
 * @package Inc\Modules\AdSync\Cli
 */
class AdSyncCommand {

	public function __construct(
		private readonly AdDeliveryService     $delivery,
		private readonly AdReconcileService    $reconcile,
		private readonly AdOutboxRepository    $outbox,
		private readonly AdSyncStateRepository $state,
		private readonly AdSyncConfig          $config,
	) {}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms ad flush', array( $this, 'flush' ) );
		WP_CLI::add_command( 'fs-lms ad health', array( $this, 'health' ) );
		WP_CLI::add_command( 'fs-lms ad status', array( $this, 'status' ) );
		WP_CLI::add_command( 'fs-lms ad reconcile', array( $this, 'reconcile' ) );
		WP_CLI::add_command( 'fs-lms ad retry-dead', array( $this, 'retryDead' ) );
	}

	/**
	 * Отправляет готовые задания в офис.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Сколько заданий за проход. По умолчанию 50.
	 *
	 * [--respect-pause]
	 * : Не отправлять, пока доставка на паузе из-за простоя офиса (для системного cron).
	 *
	 * @param string[]              $args      Позиционные аргументы
	 * @param array<string, string> $assocArgs Именованные аргументы
	 */
	public function flush( array $args, array $assocArgs ): void {
		$report = $this->delivery->deliverPending(
			max( 1, (int) ( $assocArgs['limit'] ?? 50 ) ),
			! isset( $assocArgs['respect-pause'] )
		);

		WP_CLI::log( sprintf( 'Отправлено: %d, ошибок: %d, без данных: %d.', $report['sent'], $report['failed'], $report['dead'] ) );
		if ( '' !== $report['skipped'] ) {
			WP_CLI::warning( $report['skipped'] );
		}
	}

	/**
	 * Проверяет связь с сервером в офисе (подписанный GET /v1/health).
	 */
	public function health(): void {
		$result = $this->delivery->checkConnection();

		$result['ok'] ? WP_CLI::success( $result['message'] ) : WP_CLI::error( $result['message'] );
	}

	/**
	 * Очередь и последняя доставка.
	 */
	public function status(): void {
		$state = $this->state->get();

		foreach ( $this->outbox->countByStatus() as $status => $count ) {
			WP_CLI::log( sprintf( '%-8s %d', $status, $count ) );
		}
		WP_CLI::log( 'Сервер: ' . ( $this->config->serverUrl() ?: '—' ) );
		WP_CLI::log( 'Последняя доставка: ' . ( ! empty( $state['last_delivery_at'] ) ? wp_date( 'd.m.Y H:i:s', (int) $state['last_delivery_at'] ) : '—' ) );
		if ( $this->state->pausedUntil() > 0 ) {
			WP_CLI::warning( 'Пауза до ' . wp_date( 'H:i:s', $this->state->pausedUntil() ) . ': ' . ( $state['last_error'] ?? '' ) );
		}
	}

	/**
	 * Сверка активных логинов с доменом.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Отключать лишние учётки. Без флага — режим из настроек модуля.
	 *
	 * @param string[]              $args      Позиционные аргументы
	 * @param array<string, string> $assocArgs Именованные аргументы
	 */
	public function reconcile( array $args, array $assocArgs ): void {
		$apply  = isset( $assocArgs['apply'] ) || $this->config->reconcileApply();
		$result = $this->delivery->reconcile( $this->reconcile->activeUsernames(), $apply );

		$result['ok'] ? WP_CLI::success( $result['message'] ) : WP_CLI::error( $result['message'] );
	}

	/**
	 * Возвращает «мёртвые» задания в очередь.
	 *
	 * @subcommand retry-dead
	 */
	public function retryDead(): void {
		WP_CLI::success( sprintf( 'Возвращено в очередь: %d.', $this->outbox->retryDead() ) );
	}
}
