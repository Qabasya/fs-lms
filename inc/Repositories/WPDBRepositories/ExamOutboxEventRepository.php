<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamOutboxEventDTO;
use Inc\Enums\Settings\TableName;

/** Outbox событий экзаменов: запись — в транзакции изменения данных ({@see \Inc\Services\Exam\ExamOutbox}). */
class ExamOutboxEventRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamOutbox->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamOutboxEventDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamOutboxEventDTO::fromArray( $row ) : null;
	}

	/**
	 * Берёт пачку событий в работу воркера: необработанные, срок которых наступил, без действующей аренды.
	 * Каждое событие захватывается отдельным условным `UPDATE` (аренда + счётчик попыток), поэтому два воркера
	 * не получат одно и то же событие: выигрывает тот, у кого затронута ровно одна строка.
	 * Время сравнивается с переданным UTC, а не с `NOW()`: `available_at` пишется в UTC, а пояс сервера базы может быть любым.
	 *
	 * @return ExamOutboxEventDTO[] Захваченные события в порядке `available_at`.
	 */
	/** После стольких неудач строка остаётся необработанной с `last_error` и больше не берётся в работу. */
	public const MAX_ATTEMPTS = 10;

	public function leaseBatch( string $nowUtc, string $leaseUntilUtc, int $limit = 100 ): array {
		$ids = $this->readInts( $this->wpdb->prepare(
			'SELECT id FROM %i WHERE processed_at IS NULL AND attempts < %d AND available_at <= %s AND ( leased_until IS NULL OR leased_until < %s )
			 ORDER BY available_at ASC, id ASC LIMIT %d',
			$this->table,
			self::MAX_ATTEMPTS,
			$nowUtc,
			$nowUtc,
			$limit
		) );

		$claimed = array();
		foreach ( $ids as $id ) {
			$won = 1 === $this->write( $this->wpdb->prepare(
				'UPDATE %i SET leased_until = %s, attempts = attempts + 1
				 WHERE id = %d AND processed_at IS NULL AND ( leased_until IS NULL OR leased_until < %s )',
				$this->table,
				$leaseUntilUtc,
				$id,
				$nowUtc
			) );
			if ( $won ) {
				$event = $this->find( $id );
				if ( null !== $event ) {
					$claimed[] = $event;
				}
			}
		}

		return $claimed;
	}

	/** Событие обработано: аренда снимается, повторно оно не выдаётся. */
	public function markProcessed( int $id, string $atUtc ): void {
		$this->write( $this->wpdb->prepare(
			'UPDATE %i SET processed_at = %s, leased_until = NULL, last_error = NULL WHERE id = %d',
			$this->table,
			$atUtc,
			$id
		) );
	}

	/** Сбой обработки: ошибка запоминается, событие вернётся в очередь не раньше `$nextAvailableAtUtc`. */
	public function markFailed( int $id, string $error, string $nextAvailableAtUtc ): void {
		$this->write( $this->wpdb->prepare(
			'UPDATE %i SET last_error = %s, available_at = %s, leased_until = NULL WHERE id = %d',
			$this->table,
			mb_substr( $error, 0, 1000 ),
			$nextAvailableAtUtc,
			$id
		) );
	}
}
