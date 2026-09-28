<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\DTO\AdServerResponseDTO;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;

/**
 * Class AdDeliveryService
 *
 * Доставка заданий из очереди на сервер AdSync в офисе (push): задание подписывается,
 * уходит `POST /v1/jobs`, и в том же ответе приходит результат — отдельного
 * обратного вызова на сайт нет.
 *
 * ### Что делаем с ответом ({@see classify()})
 *
 * | Ответ                                   | Задание                     | Доставка              |
 * |-----------------------------------------|-----------------------------|-----------------------|
 * | 2xx `status: done`                      | отправлено                  | дальше                |
 * | 2xx `status: failed` / 4xx (кроме 401/403) | попытка ушла, ретрай с бэкоффом; 6 неудач — «мёртвое» | дальше |
 * | нет связи, 5xx, 401/403                 | не трогаем, попытка не тратится | пауза 1м → … ≤ 1 ч, пачка прерывается |
 *
 * Простой офиса (свет, интернет, перезагрузка) не должен превращать задания в
 * «мёртвые» — поэтому недоступность сервера ставит на паузу всю доставку, а не
 * тратит попытки заданий. Отказ подписи (401/403) — это тоже не вина задания:
 * неверный секрет в wp-config, пока его не исправят, слать бессмысленно.
 *
 * Одновременно доставляет один процесс ({@see AdSyncStateRepository::acquireLock()}).
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdDeliveryService {

	/** Задание выполнено. */
	public const string DONE = 'done';

	/** Задание не выполнено по вине задания/данных — тратим попытку. */
	public const string FAILED = 'failed';

	/** Сервер недоступен или отверг подпись — задание не трогаем, доставку на паузу. */
	public const string UNAVAILABLE = 'unavailable';

	public function __construct(
		private readonly AdOutboxRepository    $outbox,
		private readonly AdProvisioningService $provisioning,
		private readonly AdServerClient        $client,
		private readonly AdSyncStateRepository $state,
	) {}

	/**
	 * Отправляет готовые задания.
	 *
	 * @param int  $limit Сколько заданий за проход
	 * @param bool $force Игнорировать паузу простоя (ручной прогон, WP-CLI)
	 *
	 * @return array{sent: int, failed: int, dead: int, skipped: string} `skipped` — почему не отправляли
	 */
	public function deliverPending( int $limit = 20, bool $force = false ): array {
		$report = array( 'sent' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => '' );

		$notReady = $this->client->notReadyReason();
		if ( '' !== $notReady ) {
			$report['skipped'] = $notReady;
			return $report;
		}
		if ( ! $force && $this->state->pausedUntil() > 0 ) {
			$report['skipped'] = 'Сервер недоступен — следующая попытка ' . wp_date( 'H:i', $this->state->pausedUntil() ) . '.';
			return $report;
		}
		if ( ! $this->state->acquireLock() ) {
			$report['skipped'] = 'Доставка уже идёт в другом процессе.';
			return $report;
		}

		try {
			foreach ( $this->outbox->listPending( $limit ) as $item ) {
				$payload = $this->provisioning->payloadFor( $item );
				if ( null === $payload ) {
					$this->outbox->markDead( $item->id, 'Нет данных для задания: заявка или ученик удалены, нет учётных данных или сохранённого пароля.' );
					++$report['dead'];
					continue;
				}

				$response = $this->client->request( 'POST', '/v1/jobs', $payload );
				$outcome  = self::classify( $response );

				if ( self::UNAVAILABLE === $outcome ) {
					$this->state->markUnreachable( $response->describe() );
					$report['skipped'] = $response->describe();
					break;
				}

				if ( self::DONE === $outcome ) {
					$this->outbox->markSent( $item->id );
					$this->state->markReachable( true );
					++$report['sent'];
					continue;
				}

				$this->outbox->markFailed( $item->id, $response->describe() );
				$this->state->markReachable( false );
				++$report['failed'];
			}
		} finally {
			$this->state->releaseLock();
		}

		return $report;
	}

	/**
	 * Судьба задания по ответу сервера.
	 *
	 * @return string self::DONE | self::FAILED | self::UNAVAILABLE
	 */
	public static function classify( AdServerResponseDTO $response ): string {
		if ( $response->isUnreachable() || $response->isServerSideProblem() ) {
			return self::UNAVAILABLE;
		}
		if ( $response->isSuccess() && 'done' === strtolower( (string) ( $response->data['status'] ?? '' ) ) ) {
			return self::DONE;
		}

		return self::FAILED;
	}

	/**
	 * Проверка соединения для кнопки в настройках: подписанный `GET /v1/health`.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function checkConnection(): array {
		$response = $this->client->request( 'GET', '/v1/health' );

		if ( $response->isSuccess() ) {
			$this->state->markReachable( false );
			$ms = (int) round( $response->seconds * 1000 );

			return array( 'ok' => true, 'message' => "Сервер отвечает ({$ms} мс)." );
		}

		return array( 'ok' => false, 'message' => $response->describe() );
	}

	/**
	 * Сверка: список активных логинов — на сервер; тот отключает в управляемых OU
	 * всех, кого нет в списке (или, в режиме «только журнал», пишет их в лог).
	 *
	 * @param string[] $usernames Логины, которые должны остаться активными
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function reconcile( array $usernames, bool $apply ): array {
		$response = $this->client->request(
			'POST',
			'/v1/reconcile',
			array( 'usernames' => array_values( $usernames ), 'apply' => $apply ),
			AdServerClient::TIMEOUT_RECONCILE
		);

		$affected = is_array( $response->data['disabled'] ?? null ) ? count( $response->data['disabled'] ) : 0;
		$aborted  = 'aborted' === ( $response->data['status'] ?? '' );
		$message  = match ( true ) {
			! $response->isSuccess() => $response->describe(),
			// Сработал предохранитель сервера (порог отключений, пустой список) — никто не тронут.
			$aborted                 => 'Сверка отменена сервером: ' . (string) ( $response->data['abort_reason'] ?? 'сработал предохранитель' ),
			default                  => sprintf( '%s: %d.', $apply ? 'Отключено учёток' : 'Отключил бы (только журнал)', $affected ),
		};

		$this->state->update( array(
			'last_reconcile_at'     => time(),
			'last_reconcile_result' => $message,
		) );

		return array( 'ok' => $response->isSuccess() && ! $aborted, 'message' => $message );
	}
}
