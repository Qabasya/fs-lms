<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;

/**
 * Class WpClock
 *
 * Адаптер для WordPress-функции current_time(), реализующий интерфейс ClockInterface.
 *
 * @package Inc\Services
 * @implements ClockInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Предоставление текущего времени** — обёртка над WordPress current_time().
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс ClockInterface для инверсии зависимостей.
 * Позволяет легко подменять источник времени в тестах (mock).
 * Используется в сервисах логирования (AuthLogWriter, DataChangeLogWriter и др.)
 * для получения временной метки события.
 *
 * ### Примечания:
 *
 * - current_time() — WordPress-функция, возвращающая текущее время с учётом
 *   настройки часового пояса в WordPress.
 * - Параметр $type может быть 'mysql' (Y-m-d H:i:s) или 'timestamp'.
 * - Параметр $gmt = true возвращает время в UTC.
 */
class WpClock implements ClockInterface {

	/**
	 * Конструктор класса.
	 */
	public function __construct() {}

	/**
	 * Возвращает текущее время.
	 *
	 * @param string $type Формат вывода: 'mysql' (datetime) или 'timestamp'
	 * @param bool   $gmt  true — UTC, false — локальное время WordPress
	 *
	 * @return string
	 */
	public function now( string $type = 'mysql', bool $gmt = false ): string {
		// current_time() — встроенная функция WordPress
		return current_time( $type, $gmt );
	}
}