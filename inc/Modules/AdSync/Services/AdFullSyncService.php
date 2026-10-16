<?php
/**
 * Ручная синхронизация AD с отчётом по каждому логину и возобновлением прогона.
 *
 * @package Inc\Modules\AdSync\Services
 */

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Enums\AdSyncEvent;
use Inc\Modules\AdSync\Enums\AdOutboxStatus;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;

/** Обновляет учётки через существующую очередь и собирает итоговый отчёт. */
class AdFullSyncService {
	private const STATE_KEY = 'manual_sync';
	private const RUN_TTL   = 1800;

	/** Собирает планирование, доставку, настройки и состояние прогона. */
	public function __construct(
		private readonly AdSyncConfig $config,
		private readonly AdProvisioningService $provisioning,
		private readonly AdOutboxRepository $outbox,
		private readonly AdSyncStateRepository $state,
		private readonly AdDeliveryService $delivery,
		private readonly AdReconcileService $reconcile,
	) {}

	/**
	 * Начинает или возобновляет незавершённый прогон без хранения паролей.
	 *
	 * @throws \RuntimeException Доставка занята или сервер недоступен.
	 */
	public function start(): array {
		$this->lock();
		try {
			$run = $this->state->get()[ self::STATE_KEY ] ?? array();
			if ( ! empty( $run['active'] ) && (int) $run['started_at'] > time() - self::RUN_TTL ) {
				return $this->progress( $run );
			}
			$health = $this->delivery->checkConnection();
			if ( ! $health['ok'] ) {
				throw new \RuntimeException( $health['message'] );
			}
			$plan    = $this->provisioning->fullSyncTargets();
			$targets = $plan['targets'];
			foreach ( $this->config->excludedUsernames() as $username ) {
				$targets[ $username ] = array(
					'event'  => AdSyncEvent::Delete->value,
					'target' => $username,
				);
			}
			$this->outbox->cancelForUsernames( array_column( $targets, 'target' ) );
			$this->outbox->cancelExclusionDeletions( $this->config->excludedUsernames() );
			$run = array(
				'id'         => bin2hex( random_bytes( 16 ) ),
				'active'     => true,
				'started_at' => time(),
				'offset'     => 0,
				'job_ids'    => array(),
				'issues'     => $plan['issues'],
			);
			foreach ( $targets as $target ) {
				$prefix                    = AdSyncEvent::Delete->value === $target['event'] ? AdOutboxItemDTO::EXCLUSION_KEY_PREFIX : 'fullsync:';
				$target['idempotency_key'] = $prefix . $run['id'] . ':' . substr( hash( 'sha256', $target['target'] ), 0, 32 );
				$run['job_ids'][]          = $this->outbox->enqueue( $target );
			}
			$this->saveRun( $run );
			return $this->progress( $run );
		} finally {
			$this->state->releaseLock();
		}
	}

	/** Обрабатывает короткую порцию: не более трёх HTTP-запросов к офису. */
	public function step( string $runId ): array {
		$run    = $this->run( $runId );
		$batch  = array_slice( $run['job_ids'], (int) $run['offset'], 3 );
		$report = $this->delivery->deliverSelected( $batch );
		if ( '' === $report['skipped'] ) {
			$run['offset'] += count( $batch );
			$this->saveRun( $run );
		}
		$result            = $this->progress( $run );
		$result['skipped'] = $report['skipped'];
		return $result;
	}

	/**
	 * Сверяет лишние учётки после доставки; при ошибках использует только журнал.
	 *
	 * @throws \RuntimeException Прогон отсутствует, не завершён или доставка занята.
	 */
	public function finish( string $runId ): array {
		$this->lock();
		try {
			$run = $this->run( $runId );
			if ( (int) $run['offset'] < count( $run['job_ids'] ) ) {
				throw new \RuntimeException( 'Синхронизация ещё не завершена.' );
			}
			$result            = $this->progress( $run );
			$hasErrors         = count( array_filter( $result['rows'], static fn( array $row ): bool => 'ok' !== $row['status'] ) ) > 0;
			$reconcile         = $this->delivery->reconcile( $this->reconcile->activeUsernames(), ! $hasErrors && $this->config->reconcileApply() );
			$result['message'] = $reconcile['message'];
			if ( ! $reconcile['ok'] ) {
				$result['rows'][] = array(
					'username' => 'Сверка лишних учёток',
					'status'   => 'error',
					'message'  => $reconcile['message'],
				);
			}
			foreach ( $reconcile['usernames'] as $username ) {
				$result['rows'][] = array(
					'username' => $username,
					'status'   => $reconcile['applied'] ? 'ok' : 'warning',
					'message'  => $reconcile['applied'] ? 'Лишняя учётка отключена и перенесена в «Отчисленные».' : 'Учётки нет в списке LMS; в режиме журнала она не отключена.',
				);
			}
			$result['ok'] = ! $hasErrors && $reconcile['ok'] && ( $reconcile['applied'] || array() === $reconcile['usernames'] );
			if ( $hasErrors && $this->config->reconcileApply() ) {
				$result['message'] .= ' Отключение лишних не выполнялось из-за ошибок обновления учёток.';
			}
			$result['counts'] = $this->outbox->countByStatus();
			$run['active']    = false;
			$run['result']    = $result;
			$this->saveRun( $run );
			return $result;
		} finally {
			$this->state->releaseLock();
		}
	}

	/** Читает текущий прогон, проверяя его непрозрачный идентификатор.
	 *
	 * @throws \RuntimeException Прогон не найден или уже завершён.
	 */
	private function run( string $runId ): array {
		$run = $this->state->get()[ self::STATE_KEY ] ?? array();
		if ( empty( $run['active'] ) || ! hash_equals( (string) ( $run['id'] ?? '' ), $runId ) ) {
			throw new \RuntimeException( 'Прогон не найден или уже завершён. Запустите синхронизацию снова.' );
		}
		return $run;
	}

	/** Собирает статусы из очереди, не читая пароли и тела заданий. */
	private function progress( array $run ): array {
		$rows = $run['issues'];
		foreach ( $run['job_ids'] as $id ) {
			$item    = $this->outbox->find( $id );
			$success = AdOutboxStatus::Sent->value === $item?->status;
			$pending = in_array( $item?->status, array( AdOutboxStatus::Pending->value, AdOutboxStatus::Failed->value ), true );
			$rows[]  = array(
				'username' => $item?->target ?? '#' . $id,
				'status'   => $success ? 'ok' : ( $pending && null === $item?->lastError ? 'pending' : 'error' ),
				'message'  => $success
					? ( $item->isExclusionDeletion() ? 'Исключение обработано: доменной учётки нет.' : 'Учётка синхронизирована: пароль, включение и параметры AD обновлены.' )
					: ( $item?->lastError ?? ( $pending ? 'Ожидает доставки.' : 'Задание недоступно или отменено.' ) ),
			);
		}
		return array(
			'run_id'    => $run['id'],
			'processed' => $run['offset'],
			'total'     => count( $run['job_ids'] ),
			'rows'      => $rows,
		);
	}

	/** Сохраняет прогон, сохраняя остальные поля состояния доставки. */
	private function saveRun( array $run ): void {
		$this->state->update( array( self::STATE_KEY => $run ) ); }

	/** Берёт общую блокировку доставки.
	 *
	 * @throws \RuntimeException Доставка уже выполняется.
	 */
	private function lock(): void {
		if ( ! $this->state->acquireLock() ) {
			throw new \RuntimeException( 'Доставка AD уже выполняется. Повторите действие через несколько секунд.' );
		}
	}
}
