<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\Enums\Log\LogChannel;

/**
 * Пакетное чтение, удаление и восстановление строк журналов для архива.
 *
 * Работает с таблицей канала «как есть» (все колонки), поэтому годится любому каналу
 * {@see LogChannel::archivable()}. Выборка идёт по ключу `id` порциями, чтобы не держать в памяти
 * сотни тысяч строк; граница `$before` — `created_at < $before`, null — без границы по дате.
 */
class LogArchiveRepository {

	private \wpdb $wpdb;

	/**
	 * @param \wpdb|null $wpdb Глобальный объект базы данных WordPress
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Колонки таблицы канала в порядке хранения.
	 *
	 * @return string[]
	 */
	public function columns( LogChannel $channel ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table( $channel ) ), ARRAY_A );

		return array_map( static fn( array $row ): string => (string) $row['Field'], (array) $rows );
	}

	/**
	 * Сколько строк попадёт в архив.
	 */
	public function countBefore( LogChannel $channel, ?string $before ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $this->dateCondition( $before ), ...$this->args( $channel, $before ) )
		);
	}

	/**
	 * Наибольший `id` среди строк архива (0 — строк нет): очистка не заденет ничего, что появилось позже.
	 */
	public function maxIdBefore( LogChannel $channel, ?string $before ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i WHERE ' . $this->dateCondition( $before ), ...$this->args( $channel, $before ) )
		);
	}

	/**
	 * Порция строк архива после `$afterId`, по возрастанию `id`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function batchBefore( LogChannel $channel, ?string $before, int $afterId, int $maxId, int $limit ): array {
		$sql  = 'SELECT * FROM %i WHERE ' . $this->dateCondition( $before ) . ' AND id > %d AND id <= %d ORDER BY id ASC LIMIT %d';
		$args = array_merge( $this->args( $channel, $before ), array( $afterId, $maxId, $limit ) );

		return (array) $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );
	}

	/**
	 * Удаляет порцию строк архива (`id <= $maxId`).
	 *
	 * @return int Сколько удалено; 0 — удалять больше нечего
	 */
	public function deleteBatchBefore( LogChannel $channel, ?string $before, int $maxId, int $limit ): int {
		$sql  = 'DELETE FROM %i WHERE ' . $this->dateCondition( $before ) . ' AND id <= %d ORDER BY id ASC LIMIT %d';
		$args = array_merge( $this->args( $channel, $before ), array( $maxId, $limit ) );
		$this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) );

		return (int) $this->wpdb->rows_affected;
	}

	/**
	 * Возвращает строки в таблицу (восстановление из архива). Строка с уже занятым `id` пропускается.
	 *
	 * @param string[]                                $columns Колонки (должны существовать в таблице)
	 * @param array<int, array<int, string|null>>     $rows    Значения в порядке `$columns`; null — SQL NULL
	 *
	 * @return int Сколько строк вставлено
	 */
	public function insertIgnore( LogChannel $channel, array $columns, array $rows ): int {
		if ( array() === $rows ) {
			return 0;
		}

		$known = $this->columns( $channel );
		if ( array() !== array_diff( $columns, $known ) ) {
			throw new \InvalidArgumentException( 'В архиве есть колонки, которых нет в таблице: ' . implode( ', ', array_diff( $columns, $known ) ) );
		}

		$table  = $this->table( $channel );
		$names  = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
		$tuples = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $value ) {
				$cells[] = null === $value ? 'NULL' : $this->wpdb->prepare( '%s', $value );
			}
			$tuples[] = '(' . implode( ', ', $cells ) . ')';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- значения экранированы prepare() выше, имена — %i
		$this->wpdb->query( $this->wpdb->prepare( "INSERT IGNORE INTO %i ($names) VALUES ", $table, ...$columns ) . implode( ', ', $tuples ) );

		return (int) $this->wpdb->rows_affected;
	}

	private function table( LogChannel $channel ): string {
		return $channel->tableName()->prefixed();
	}

	/** Условие по дате: `created_at < %s` либо тождественно истинное. */
	private function dateCondition( ?string $before ): string {
		return null === $before ? '1=1' : 'created_at < %s';
	}

	/**
	 * @return array<int, string>
	 */
	private function args( LogChannel $channel, ?string $before ): array {
		return null === $before ? array( $this->table( $channel ) ) : array( $this->table( $channel ), $before );
	}
}
