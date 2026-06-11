<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\Enums\LogEvent;

/**
 * Контракт внутренней шины событий логирования.
 *
 * Источники событий (сервисы, колбэки) зависят только от этого интерфейса,
 * а не от конкретного диспетчера — обеспечивает DIP и тестируемость.
 *
 * Subscriber'ы регистрируются через subscribe() в своём register().
 * Источники вызывают dispatch() после успешного коммита операции.
 */
interface LogEventDispatcherInterface {

	/**
	 * Подписывает listener на конкретное событие.
	 *
	 * @param LogEvent $event    Событие, на которое подписываемся.
	 * @param callable $listener Обработчик; получает один аргумент — payload (LogEventInterface).
	 */
	public function subscribe( LogEvent $event, callable $listener ): void;

	/**
	 * Рассылает событие всем подписанным listener'ам.
	 *
	 * Выполняется синхронно. Исключения внутри listener'ов подавляются
	 * (логируются через PluginLogger), наружу не бросаются — падение лога
	 * не должно ронять основной поток.
	 *
	 * @param LogEvent          $event   Тип события.
	 * @param LogEventInterface $payload Типизированный payload события.
	 */
	public function dispatch( LogEvent $event, LogEventInterface $payload ): void;
}
