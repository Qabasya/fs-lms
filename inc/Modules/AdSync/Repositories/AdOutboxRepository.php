<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Repositories;

use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Enums\AdOutboxStatus;
use Inc\Modules\AdSync\Schema\AdSchema;

/**
 * Class AdOutboxRepository
 *
 * Доступ к таблице `fs_lms_ad_outbox` (очередь синхронизации с AD). PII-free.
 *
 * @package Inc\Modules\AdSync\Repositories
 */
class AdOutboxRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = AdSchema::table();
	}

	/**
	 * Ставит задачу в очередь. Возвращает ID строки.
	 *
	 * @param array{event:string,application_id?:?int,person_id?:?int,target?:?string,idempotency_key:string} $data
	 */
	public function enqueue( array $data ): int {
		$this->wpdb->insert(
			$this->table,
			array(
				'event'           => $data['event'],
				'application_id'  => $data['application_id'] ?? null,
				'person_id'       => $data['person_id'] ?? null,
				'target'          => $data['target'] ?? null,
				'idempotency_key' => $data['idempotency_key'],
				'status'          => AdOutboxStatus::Pending->value,
				'attempts'        => 0,
			)
		);
		return (int) $this->wpdb->insert_id;
	}

	public function markSent( int $id ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'status'  => AdOutboxStatus::Sent->value,
				'sent_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Помечает попытку неудачной: инкремент attempts, статус failed (или dead при превышении),
	 * запись ошибки и времени следующей попытки (экспоненциальный backoff).
	 */
	/** @return bool Задание стало «мёртвым» (попытки кончились). */
	public function markFailed( int $id, string $error, int $maxAttempts = 6 ): bool {
		$row      = $this->find( $id );
		$attempts = ( $row?->attempts ?? 0 ) + 1;
		$dead     = $attempts >= $maxAttempts;
		$backoff  = (int) min( 3600, 60 * ( 2 ** ( $attempts - 1 ) ) ); // 1м,2м,4м,…≤1ч

		$this->wpdb->update(
			$this->table,
			array(
				'status'          => $dead ? AdOutboxStatus::Dead->value : AdOutboxStatus::Failed->value,
				'attempts'        => $attempts,
				'last_error'      => mb_substr( $error, 0, 1000 ),
				'next_attempt_at' => $dead ? null : gmdate( 'Y-m-d H:i:s', time() + $backoff ),
			),
			array( 'id' => $id )
		);

		return $dead;
	}

	/**
	 * Сразу «мёртвое»: отправлять нечего (заявку удалили, данных учётки нет) —
	 * ретраи ничего не изменят.
	 */
	public function markDead( int $id, string $error ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'status'          => AdOutboxStatus::Dead->value,
				'last_error'      => mb_substr( $error, 0, 1000 ),
				'next_attempt_at' => null,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * «Мёртвые» — снова в очередь с чистым счётчиком попыток (кнопка в настройках).
	 *
	 * @return int Сколько заданий возвращено
	 */
	public function retryDead(): int {
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table} SET status = %s, attempts = 0, next_attempt_at = NULL WHERE status = %s",
				AdOutboxStatus::Pending->value,
				AdOutboxStatus::Dead->value
			)
		);
	}

	/**
	 * Число заданий по статусам (для страницы настроек).
	 *
	 * @return array<string, int> status => count
	 */
	public function countByStatus(): array {
		$rows   = $this->wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table} GROUP BY status" );
		$counts = array_fill_keys( array_map( static fn( AdOutboxStatus $s ): string => $s->value, AdOutboxStatus::cases() ), 0 );
		foreach ( $rows ?: array() as $row ) {
			$counts[ (string) $row->status ] = (int) $row->n;
		}

		return $counts;
	}

	public function find( int $id ): ?AdOutboxItemDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id )
		);
		return $row ? AdOutboxItemDTO::fromRow( $row ) : null;
	}

	/**
	 * Задания, готовые к отправке в офис: pending, либо failed с наступившим next_attempt_at.
	 *
	 * @return AdOutboxItemDTO[]
	 */
	public function listPending( int $limit = 50 ): array {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE status = %s
				    OR ( status = %s AND ( next_attempt_at IS NULL OR next_attempt_at <= %s ) )
				 ORDER BY id ASC
				 LIMIT %d",
				AdOutboxStatus::Pending->value,
				AdOutboxStatus::Failed->value,
				$now,
				$limit
			)
		);

		return array_map( static fn( $r ) => AdOutboxItemDTO::fromRow( $r ), $rows ?: array() );
	}

	/**
	 * Последнее задание по логину учётки (`target`) — что сайт последним делал с учёткой:
	 * `deprovision` значит, что она сейчас в «Отчисленных».
	 */
	public function latestByTarget( string $username ): ?AdOutboxItemDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE target = %s ORDER BY id DESC LIMIT 1",
				$username
			)
		);
		return $row ? AdOutboxItemDTO::fromRow( $row ) : null;
	}

	/** Последняя строка по заявке (для статус-поллинга фронта). */
	public function latestByApplication( int $applicationId ): ?AdOutboxItemDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE application_id = %d ORDER BY id DESC LIMIT 1",
				$applicationId
			)
		);
		return $row ? AdOutboxItemDTO::fromRow( $row ) : null;
	}
}
