<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Shared\Traits\RaisesDbError;

/**
 * Общая база репозиториев экзаменов: чтение и запись с разбором номера ошибки MySQL.
 *
 * Ошибка базы всегда становится исключением — ни чтение, ни запись не выдают её за «строки нет»
 * или «ничего не изменилось»:
 * 1213/1205 → {@see RetryableDbException} (сервис повторит транзакцию),
 * 1062 → {@see DuplicateKeyException}, остальное → \RuntimeException.
 */
abstract class AbstractExamRepository {

	use RaisesDbError;

	protected \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Выполняет готовый запрос записи.
	 *
	 * @return int Число затронутых строк.
	 */
	protected function write( string $sql ): int {
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			$this->raise();
		}
		return (int) $result;
	}

	/**
	 * `INSERT` строки в таблицу.
	 *
	 * @param array<string, mixed> $data
	 */
	protected function insertRow( string $table, array $data ): int {
		if ( false === $this->wpdb->insert( $table, $data ) ) {
			$this->raise();
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * `UPDATE` строк по условию равенства.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 *
	 * @return int Число затронутых строк (0 — значения не изменились или строки нет).
	 */
	protected function updateRow( string $table, array $data, array $where ): int {
		$result = $this->wpdb->update( $table, $data, $where );
		if ( false === $result ) {
			$this->raise();
		}
		return (int) $result;
	}

	/**
	 * Версионированное обновление: `SET <поля>, version = version + 1, updated_at = %s WHERE id = %d AND version = %d`.
	 * Затронута ровно одна строка — `true`; версия устарела (или строки нет) — `false`: вызывающий сервис отвечает `ExamStale`.
	 *
	 * @param array<string, scalar|null> $data Колонка → значение (имена колонок — из кода сервиса, не из запроса).
	 */
	protected function updateVersioned( string $table, int $id, array $data, int $expectedVersion ): bool {
		$sets = array();
		$args = array( $table );
		foreach ( $data as $column => $value ) {
			if ( 1 !== preg_match( '/^[a-z_]+$/', (string) $column ) ) {
				throw new \InvalidArgumentException( 'Недопустимое имя колонки.' );
			}
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
			} else {
				$sets[] = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
				$args[] = $value;
			}
		}
		$sets[] = 'version = version + 1';
		$sets[] = 'updated_at = %s';
		$args[] = gmdate( 'Y-m-d H:i:s' );
		$args[] = $id;
		$args[] = $expectedVersion;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имена колонок проверены регулярным выражением выше.
		return 1 === $this->write( $this->wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND version = %d', ...$args ) );
	}

	/**
	 * Одна строка или null, если её нет. Ошибка базы — исключение, а не «строки нет».
	 *
	 * @return array<string, mixed>|null
	 */
	protected function readRow( string $sql ): ?array {
		$row = $this->wpdb->get_row( $sql, ARRAY_A );
		$this->assertReadSucceeded();
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function readRows( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		$this->assertReadSucceeded();
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/** @return int[] Значения первой колонки как целые. */
	protected function readInts( string $sql ): array {
		$values = $this->wpdb->get_col( $sql );
		$this->assertReadSucceeded();
		return array_map( 'intval', is_array( $values ) ? $values : array() );
	}

	protected function readInt( string $sql ): int {
		$value = $this->wpdb->get_var( $sql );
		$this->assertReadSucceeded();
		return (int) $value;
	}

	private function assertReadSucceeded(): void {
		if ( '' !== (string) $this->wpdb->last_error ) {
			$this->raise();
		}
	}

	protected function raise(): never {
		$this->raiseDbError();
	}
}
