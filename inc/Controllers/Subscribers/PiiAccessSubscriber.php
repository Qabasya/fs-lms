<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\PiiRevealedEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Services\Log\PiiAccessLogWriter;

/**
 * Class PiiAccessSubscriber
 *
 * Подписчик на события раскрытия персональных данных (PII).
 * Записывает факт доступа к PII в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события** — регистрация обработчика на событие раскрытия PII.
 * 2. **Запись в лог** — при получении события вызывает PiiAccessLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Использует LogEventDispatcherInterface для подписки и PiiAccessLogWriter для записи.
 * Делает систему логирования расширяемой и слабосвязанной.
 *
 * ### Примечания:
 *
 * - Подписывается на событие PiiRevealed (раскрытие PII-поля).
 * - Записывает в лог ID человека (targetPersonId), какие поля были раскрыты
 *   (fieldsAccessed) и причину доступа (accessReason) для соблюдения 152-ФЗ.
 */
class PiiAccessSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 * @param PiiAccessLogWriter          $writer    Райтер для записи логов доступа к PII
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly PiiAccessLogWriter          $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		// subscribe() — подписка на событие PiiRevealed
		$this->logEvents->subscribe( LogEvent::PiiRevealed, array( $this, 'handle' ) );
	}

	/**
	 * Обработчик события раскрытия персональных данных.
	 * Записывает в лог информацию о доступе к PII.
	 *
	 * @param PiiRevealedEvent $event Событие раскрытия PII
	 *
	 * @return void
	 */
	public function handle( PiiRevealedEvent $event ): void {
		$this->writer->record( $event->targetPersonId, $event->fieldsAccessed, $event->accessReason );
	}
}