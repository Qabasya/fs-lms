<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

/**
 * Согласованный неизменный срез данных во время всех запросов рейтинга.
 * Защита от вложенной транзакции использует MariaDB @@in_transaction.
 * Если сервер не поддерживает эту переменную (например, MySQL 8), снимок
 * пропускается: отсутствие возможности доказать безопасность — не разрешение
 * неявно зафиксировать чужую транзакцию через START TRANSACTION.
 */
class RankingSnapshotTransaction {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	public function read( callable $operation ): array {
		$active = $this->wpdb->get_var( 'SELECT @@session.in_transaction' );
		if ( null === $active || '' !== (string) $this->wpdb->last_error ) {
			throw new \RuntimeException( 'Снимок рейтинга пропущен: сервер БД не позволяет проверить активную транзакцию (требуется MariaDB с поддержкой @@session.in_transaction).' );
		}
		if ( 0 !== (int) $active ) {
			throw new \RuntimeException( 'Нельзя начать снимок рейтинга внутри другой транзакции.' );
		}
		// SET TRANSACTION действует только на следующую транзакцию; запоминаем
		// значение сессии на случай, если START не выполнится и SET останется ожидающим.
		$previousSuppression = $this->wpdb->suppress_errors( true );
		try {
			$defaultIsolation = $this->wpdb->get_var( 'SELECT @@session.transaction_isolation' );
			if ( null === $defaultIsolation ) {
				$defaultIsolation = $this->wpdb->get_var( 'SELECT @@session.tx_isolation' );
			}
		} finally {
			$this->wpdb->suppress_errors( $previousSuppression );
		}
		$defaultIsolation = strtoupper( str_replace( '-', ' ', (string) $defaultIsolation ) );
		if ( ! in_array( $defaultIsolation, array( 'READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE' ), true ) ) {
			throw new \RuntimeException( 'Не удалось проверить уровень изоляции базы данных.' );
		}
		if ( false === $this->wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) ) {
			throw new \RuntimeException( 'Не удалось начать транзакцию снимка рейтинга.' );
		}
		if ( false === $this->wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) {
			$this->wpdb->query( 'SET TRANSACTION ISOLATION LEVEL ' . $defaultIsolation );
			throw new \RuntimeException( 'Не удалось начать транзакцию снимка рейтинга.' );
		}
		try {
			$result = $operation();
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Не удалось завершить снимок рейтинга.' );
			}
			return $result;
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}
}
