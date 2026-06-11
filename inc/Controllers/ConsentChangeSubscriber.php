<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\ConsentChangedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\ConsentChangeLogWriter;

/**
 * Class ConsentChangeSubscriber
 *
 * Подписчик на события изменения согласий. Записывает изменения в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события** — регистрация обработчика на событие изменения согласия.
 * 2. **Запись в лог** — при получении события вызывает ConsentChangeLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Использует LogEventDispatcherInterface для подписки и ConsentChangeLogWriter для записи.
 * Делает систему логирования расширяемой и слабосвязанной.
 */
class ConsentChangeSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 * @param ConsentChangeLogWriter      $writer    Райтер для записи логов изменений согласий
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly ConsentChangeLogWriter      $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		// subscribe() — подписка на событие ConsentChanged
		$this->logEvents->subscribe( LogEvent::ConsentChanged, array( $this, 'handle' ) );
	}

	/**
	 * Обработчик события изменения согласия.
	 * Записывает изменения (старый и новый хеш документа согласия) в лог.
	 *
	 * @param ConsentChangedEvent $event Событие изменения согласия
	 *
	 * @return void
	 */
	public function handle( ConsentChangedEvent $event ): void {
		$this->writer->record( $event->personId, $event->consentType, $event->oldHash, $event->newHash );
	}
}