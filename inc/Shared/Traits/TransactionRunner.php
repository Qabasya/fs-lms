<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

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
 * RelationshipService и др.). Требует доступа к `$wpdb` — либо через `$GLOBALS['wpdb']`,
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

		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $fn();
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}
}
