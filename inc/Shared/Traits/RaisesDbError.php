<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\Repositories\WPDBRepositories\RetryableDbException;

/**
 * Превращает ошибку последнего запроса `$wpdb` в исключение по номеру ошибки MySQL.
 *
 * 1213/1205 → {@see RetryableDbException} (транзакцию можно повторить), 1062 → {@see DuplicateKeyException},
 * остальное → `\RuntimeException`. Класс-потребитель обязан иметь свойство `$wpdb` (`\wpdb`).
 */
trait RaisesDbError {

	protected function raiseDbError(): never {
		$dbh     = $this->wpdb->dbh ?? null;
		$errno   = $dbh instanceof \mysqli ? $dbh->errno : 0;
		$message = (string) $this->wpdb->last_error;

		if ( 1213 === $errno || 1205 === $errno ) {
			throw new RetryableDbException( $message );
		}
		if ( 1062 === $errno ) {
			throw new DuplicateKeyException( $message );
		}
		throw new \RuntimeException( '' !== $message ? $message : 'Ошибка записи в базу данных.' );
	}
}
