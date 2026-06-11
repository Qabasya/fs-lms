<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\LogEventInterface;
use Inc\Enums\LogEvent;
use Inc\Shared\PluginLogger;

/**
 * Внутренняя синхронная шина событий логирования.
 *
 * Регистрируется в DI как singleton. Subscriber'ы подписываются через subscribe()
 * в своём register(). Источники вызывают dispatch() строго после успешного коммита.
 *
 * Listener'ы изолированы друг от друга: исключение в одном не прерывает остальных
 * и не ронает основной поток приложения.
 */
class LogEventDispatcher implements LogEventDispatcherInterface {

	/** @var array<string, callable[]> */
	private array $listeners = [];

	public function subscribe( LogEvent $event, callable $listener ): void {
		$this->listeners[ $event->value ][] = $listener;
	}

	public function dispatch( LogEvent $event, LogEventInterface $payload ): void {
		foreach ( $this->listeners[ $event->value ] ?? [] as $listener ) {
			try {
				$listener( $payload );
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'LogEventDispatcher', $e, array( 'event' => $event->value ) );
			}
		}
	}
}
