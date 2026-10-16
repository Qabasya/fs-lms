<?php
/**
 * Исключение логинов из синхронизации AD и постановка удаления в очередь.
 *
 * @package Inc\Modules\AdSync\Services
 */

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Enums\AdSyncEvent;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;

/** Исключения AD: отмена старых заданий и удаление существующей доменной учётки. */
class AdExclusionService {

	/** Собирает настройки, очередь и общую блокировку доставки. */
	public function __construct(
		private readonly AdSyncConfig $config,
		private readonly AdOutboxRepository $outbox,
		private readonly AdSyncStateRepository $state,
	) {}

	/**
	 * Сохраняет исключения без одновременной доставки заданий.
	 *
	 * @param string[] $usernames Исключаемые логины.
	 * @return array{cancelled: int, queued: int}
	 * @throws \RuntimeException Очередь занята или запись задания не удалась.
	 */
	public function save( array $usernames ): array {
		if ( ! $this->state->acquireLock() ) {
			throw new \RuntimeException( 'Сейчас выполняется доставка AD. Повторите сохранение через несколько секунд.' );
		}
		try {
			$previous   = $this->config->excludedUsernames();
			$usernames  = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( string $name ): string => mb_strtolower( trim( $name ) ),
							$usernames
						),
						static fn( string $name ): bool => '' !== $name
					)
				)
			);
			$cancelled  = $this->outbox->cancelForUsernames( $usernames );
			$cancelled += $this->outbox->cancelExclusionDeletions( array_values( array_diff( $previous, $usernames ) ) );
			$queued     = 0;
			foreach ( array_diff( $usernames, $previous ) as $username ) {
				$this->outbox->enqueue(
					array(
						'event'           => AdSyncEvent::Delete->value,
						'target'          => $username,
						'idempotency_key' => AdOutboxItemDTO::EXCLUSION_KEY_PREFIX . hash( 'sha256', $username ) . ':' . bin2hex( random_bytes( 8 ) ),
					)
				);
				++$queued;
			}
			$this->config->save( array( 'excluded_usernames' => $usernames ) );
			return array(
				'cancelled' => $cancelled,
				'queued'    => $queued,
			);
		} finally {
			$this->state->releaseLock();
		}
	}
}
