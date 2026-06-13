<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EmailSentEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\EmailLogWriter;

/**
 * Class EmailSubscriber
 *
 * Подписчик на события отправки email. Записывает отправленные письма в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события** — регистрация обработчика на событие отправки email.
 * 2. **Запись в лог** — при получении события вызывает EmailLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Использует LogEventDispatcherInterface для подписки и EmailLogWriter для записи.
 * Делает систему логирования расширяемой и слабосвязанной.
 *
 * ### Примечания:
 *
 * - Подписывается на событие EmailSent.
 * - Записывает в лог тип письма (emailType), ID человека (targetPersonId),
 *   статус отправки (success) и сообщение об ошибке (если есть).
 */
class EmailSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 * @param EmailLogWriter              $writer    Райтер для записи логов отправки email
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly EmailLogWriter              $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		// subscribe() — подписка на событие EmailSent
		$this->logEvents->subscribe( LogEvent::EmailSent, array( $this, 'handle' ) );
	}

	/**
	 * Обработчик события отправки email.
	 * Записывает информацию об отправленном письме в лог.
	 *
	 * @param EmailSentEvent $event Событие отправки email
	 *
	 * @return void
	 */
	public function handle( EmailSentEvent $event ): void {
		$this->writer->record(
			$event->emailType->value,
			$event->targetPersonId,
			$event->recipientEmail,
			$event->success,
			$event->errorMessage ?? '',
		);
	}
}