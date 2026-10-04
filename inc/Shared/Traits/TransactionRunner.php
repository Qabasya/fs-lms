<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

use Inc\Repositories\WPDBRepositories\RetryableDbException;

/**
 * Trait TransactionRunner
 *
 * Оборачивает бизнес-логику в транзакцию БД с автоматическим откатом при исключении.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Атомарность операций** — гарантирует, что группа INSERT/UPDATE либо выполняется целиком,
 *    либо полностью откатывается при любой ошибке.
 * 2. **Прозрачная обработка исключений** — перебрасывает исключение после ROLLBACK,
 *    не поглощая его.
 *
 * ### Архитектурная роль:
 *
 * Используется в сервисах, выполняющих составные операции с БД (EnrollmentService,
 * ApplicationService и др.). Требует доступа к `$wpdb` — либо через `$GLOBALS['wpdb']`,
 * либо через инжектированный экземпляр в классе-потребителе.
 *
 * ### Ограничения:
 *
 * - `wp_insert_user()` и другие WP-функции, имеющие собственные side effects, должны
 *   вызываться вне транзакции — они не поддерживают откат.
 * - Вложенные транзакции не поддерживаются InnoDB без SAVEPOINT.
 */
trait TransactionRunner {

	/**
	 * Выполняет callable внутри транзакции.
	 *
	 * При успехе фиксирует транзакцию и возвращает результат callable.
	 * При любом исключении откатывает транзакцию и перебрасывает исключение.
	 *
	 * @param callable $fn Функция с бизнес-логикой; её возвращаемое значение проксируется.
	 *
	 * @throws \Throwable Перебрасывает исходное исключение после ROLLBACK.
	 *
	 * @return mixed
	 */
	public function inTransaction( callable $fn ): mixed {
		global $wpdb;

		$this->transactionControl( 'START TRANSACTION' );

		try {
			$result = $fn();
			$this->transactionControl( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Как {@see inTransaction()}, но повторяет транзакцию при взаимной блокировке (1213)
	 * или таймауте блокировки (1205). Любое другое исключение — ROLLBACK и проброс без повтора.
	 *
	 * @param callable $fn          Тело транзакции; должно быть безопасным для повторного запуска.
	 * @param int      $maxAttempts Сколько раз пробовать всего.
	 *
	 * @throws \Throwable
	 *
	 * @return mixed
	 */
	public function inTransactionWithRetry( callable $fn, int $maxAttempts = 3 ): mixed {
		global $wpdb;

		$attempt = 0;
		while ( true ) {
			++$attempt;
			$this->transactionControl( 'START TRANSACTION' );

			try {
				$result = $fn();
				$this->transactionControl( 'COMMIT' );
				return $result;
			} catch ( RetryableDbException $e ) {
				$wpdb->query( 'ROLLBACK' );
				if ( $attempt >= $maxAttempts ) {
					throw $e;
				}
				usleep( random_int( 20000, 80000 ) );
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
		}
	}

	/**
	 * Граница транзакции с проверкой результата: провал `START TRANSACTION` или `COMMIT`
	 * не должен выглядеть как успех — иначе тело транзакции пишется вне неё или теряется.
	 *
	 * @param 'START TRANSACTION'|'COMMIT' $statement
	 *
	 * @throws \RuntimeException Если база отказала в выполнении.
	 */
	private function transactionControl( string $statement ): void {
		global $wpdb;

		if ( false === $wpdb->query( $statement ) ) {
			throw new \RuntimeException( sprintf( 'Не удалось выполнить %s: %s', $statement, (string) $wpdb->last_error ) );
		}
	}
}
