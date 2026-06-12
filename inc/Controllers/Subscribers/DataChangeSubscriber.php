<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\PersonDataChangedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\DataChangeLogWriter;

/**
 * Class DataChangeSubscriber
 *
 * Подписчик на события изменения персональных данных (Person). Записывает изменения в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события** — регистрация обработчика на событие изменения данных лица.
 * 2. **Запись в лог** — при получении события вызывает DataChangeLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Использует LogEventDispatcherInterface для подписки и DataChangeLogWriter для записи.
 * Делает систему логирования расширяемой и слабосвязанной.
 */
class DataChangeSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 * @param DataChangeLogWriter         $writer    Райтер для записи логов изменений данных
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly DataChangeLogWriter         $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		// subscribe() — подписка на событие PersonDataChanged
		$this->logEvents->subscribe( LogEvent::PersonDataChanged, array( $this, 'handle' ) );
	}

	/**
	 * Обработчик события изменения персональных данных.
	 * Записывает информацию об изменении поля (старое и новое значение) в лог.
	 *
	 * @param PersonDataChangedEvent $event Событие изменения данных лица
	 *
	 * @return void
	 */
	public function handle( PersonDataChangedEvent $event ): void {
		$this->writer->record(
			$event->targetPersonId,
			$event->fieldName,
			null !== $event->oldValue ? (string) $event->oldValue : null,
			null !== $event->newValue ? (string) $event->newValue : null,
		);
	}
}