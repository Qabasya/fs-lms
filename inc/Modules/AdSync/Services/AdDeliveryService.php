<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdServerResponseDTO;
use Inc\Modules\AdSync\Enums\AdOutboxStatus;
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
 * Итог — в журнал «Зачисления» ({@see AdAuditLogger}): выполненное задание и «мёртвое»;
 * промежуточные ошибки с ретраем туда не пишутся.
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
		private readonly AdAuditLogger         $audit,
		private readonly AdSyncConfig $config,
	) {}

	/**
	 * Отправляет готовые задания.
	 *
	 * @param int  $limit Сколько заданий за проход
	 * @param bool $force Игнорировать паузу простоя (ручной прогон, WP-CLI)
	 *
	 * @return array{sent: int, failed: int, dead: int, skipped: string, cancelled: int} `skipped` — почему не отправляли
	 */
	public function deliverPending( int $limit = 20, bool $force = false ): array {
		return $this->deliverBatch( fn(): array => $this->outbox->listPending( $limit ), $force );
	}

	/** Возвращает исправимые мёртвые задания; удалённые источники сначала отменяются. */
	public function retryDead(): array {
		if ( ! $this->state->acquireLock() ) {
			throw new \RuntimeException( 'Доставка AD уже выполняется. Повторите действие через несколько секунд.' );
		}
		try {
			$cancelled = $this->provisioning->cancelObsoleteJobs();
			return array( 'retried' => $this->outbox->retryDead(), 'cancelled' => $cancelled );
		} finally {
			$this->state->releaseLock();
		}
	}

	/** Отправляет не более трёх заданий ручного прогона, не затрагивая остальную очередь. */
	public function deliverSelected( array $ids ): array {
		return $this->deliverBatch( function () use ( $ids ): array {
			$items = array();
			foreach ( array_slice( array_unique( $ids ), 0, 3 ) as $id ) {
				$item = $this->outbox->find( (int) $id );
				if ( null !== $item && in_array( $item->status, array( AdOutboxStatus::Pending->value, AdOutboxStatus::Failed->value ), true ) ) {
					$items[] = $item;
				}
			}
			return $items;
		}, true );
	}

	/** Единая доставка под блокировкой, включая повторное чтение статусов заданий. */
	private function deliverBatch( callable $items, bool $force ): array {
		$report = array( 'sent' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => '', 'cancelled' => 0 );

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
			$this->outbox->cancelForUsernames( $this->config->excludedUsernames() );
			$report['cancelled'] = $this->provisioning->cancelObsoleteJobs();
			foreach ( $items() as $item ) {
				$excluded = $this->config->isExcluded( (string) $item->target );
				if ( ( $excluded && ! $item->isExclusionDeletion() ) || ( ! $excluded && $item->isExclusionDeletion() ) ) {
					$this->outbox->markCancelled( $item->id );
					continue;
				}
				$prepared = $this->provisioning->prepareJob( $item );
				if ( $prepared->obsolete ) {
					$this->outbox->markCancelled( $item->id, (string) $prepared->error );
					++$report['cancelled'];
					continue;
				}
				$payload = $prepared->payload;
				if ( null === $payload ) {
					$error = (string) $prepared->error;
					$this->outbox->markDead( $item->id, $error );
					$this->audit->dead( $item, $error );
					++$report['dead'];
					continue;
				}

				if ( ! $item->isExclusionDeletion() && $this->config->isExcluded( (string) ( $payload['username'] ?? '' ) ) ) {
					$this->outbox->markCancelled( $item->id );
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
					$this->audit->done( $item, (string) ( $response->data['outcome'] ?? '' ) );
					++$report['sent'];
					continue;
				}

				if ( $this->outbox->markFailed( $item->id, $response->describe() ) ) {
					$this->audit->dead( $item, (string) ( $response->data['error'] ?? '' ) ?: $response->describe() );
				}
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

		$valid = in_array( $response->data['status'] ?? '', array( 'ok', 'aborted' ), true ) && is_array( $response->data['disabled'] ?? null );
		$affected = is_array( $response->data['disabled'] ?? null ) ? count( $response->data['disabled'] ) : 0;
		$aborted  = 'aborted' === ( $response->data['status'] ?? '' );
		$message  = match ( true ) {
			! $response->isSuccess() => $response->describe(),
			! $valid => 'Сервер вернул некорректный ответ на сверку.',
			// Сработал предохранитель сервера (порог отключений, пустой список) — никто не тронут.
			$aborted                 => 'Сверка отменена сервером: ' . (string) ( $response->data['abort_reason'] ?? 'сработал предохранитель' ),
			default                  => sprintf( '%s: %d.', $apply ? 'Отключено учёток' : 'Отключил бы (только журнал)', $affected ),
		};

		$this->state->update( array(
			'last_reconcile_at'     => time(),
			'last_reconcile_result' => $message,
		) );

		return array(
			'ok' => $response->isSuccess() && $valid && ! $aborted,
			'message' => $message,
			'usernames' => is_array( $response->data['disabled'] ?? null ) ? array_values( $response->data['disabled'] ) : array(),
			'applied' => (bool) ( $response->data['applied'] ?? $apply ),
		);
	}
}
