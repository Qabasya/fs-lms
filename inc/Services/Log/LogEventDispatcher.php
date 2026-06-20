<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\LogEventInterface;
use Inc\Enums\Log\LogEvent;
use Inc\Shared\PluginLogger;

/**
 * Class LogEventDispatcher
 *
 * Внутренняя синхронная шина событий логирования.
 *
 * @package Inc\Services\Log
 * @implements LogEventDispatcherInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация слушателей** — подписка обработчиков на события логирования.
 * 2. **Диспетчеризация событий** — вызов всех слушателей, подписанных на событие.
 * 3. **Изоляция ошибок** — исключения в одном слушателе не прерывают выполнение остальных.
 *
 * ### Архитектурная роль:
 *
 * Регистрируется в DI-контейнере как singleton.
 * Subscriber'ы подписываются через subscribe() в своём register().
 * Источники вызывают dispatch() строго после успешного коммита транзакции.
 *
 * ### Принципы работы:
 *
 * - Слушатели изолированы друг от друга: исключение в одном не прерывает остальных
 * - Ошибки логируются через PluginLogger, но не роняют основной поток приложения
 * - События обрабатываются синхронно (не асинхронно)
 *
 * ### Пример использования:
 *
 * ```php
 * $dispatcher->subscribe( LogEvent::PiiRevealed, function( PiiRevealedEvent $event ) {
 *     $this->writer->record( ... );
 * } );
 *
 * $dispatcher->dispatch( LogEvent::PiiRevealed, $event );
 * ```
 */
class LogEventDispatcher implements LogEventDispatcherInterface {

	/**
	 * Массив слушателей для каждого события.
	 *
	 * @var array<string, callable[]>
	 */
	private array $listeners = [];

	/**
	 * Конструктор диспетчера.
	 */
	public function __construct() {}

	/**
	 * Подписывает слушатель на событие.
	 *
	 * @param LogEvent $event    Событие
	 * @param callable $listener Функция-обработчик
	 *
	 * @return void
	 */
	public function subscribe( LogEvent $event, callable $listener ): void {
		$this->listeners[ $event->value ][] = $listener;
	}

	/**
	 * Вызывает всех слушателей, подписанных на событие.
	 *
	 * @param LogEvent          $event   Событие
	 * @param LogEventInterface $payload Данные события
	 *
	 * @return void
	 */
	public function dispatch( LogEvent $event, LogEventInterface $payload ): void {
		// Перебор всех слушателей, подписанных на данное событие
		foreach ( $this->listeners[ $event->value ] ?? [] as $listener ) {
			try {
				// Вызов обработчика
				$listener( $payload );
			} catch ( \Throwable $e ) {
				// Логирование ошибки (не прерываем выполнение остальных слушателей)
				PluginLogger::exception( 'LogEventDispatcher', $e, array( 'event' => $event->value ) );
			}
		}
	}
}