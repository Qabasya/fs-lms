<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\EnrollmentAuditLogWriter;

/**
 * Class EnrollmentAuditSubscriber
 *
 * Подписчик на события, связанные с зачислениями студентов.
 * Записывает изменения статуса зачисления в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события** — регистрация обработчика на все события, связанные с зачислениями.
 * 2. **Запись в лог** — при получении события вызывает EnrollmentAuditLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Использует LogEventDispatcherInterface для подписки и EnrollmentAuditLogWriter для записи.
 * Делает систему логирования расширяемой и слабосвязанной.
 *
 * ### Отслеживаемые события:
 *
 * - StudentEnrolled — студент зачислен
 * - EnrollmentFailed — ошибка при зачислении
 * - StudentExpelled — студент отчислен
 * - StudentRestored — студент восстановлен
 * - EnrollmentStarted — процесс зачисления начат
 * - EnrollmentCanceled — процесс зачисления отменён
 */
class EnrollmentAuditSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 * @param EnrollmentAuditLogWriter    $writer    Райтер для записи логов зачислений
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly EnrollmentAuditLogWriter    $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		$handler = array( $this, 'handle' );

		// Подписка на все события, связанные с зачислениями
		$this->logEvents->subscribe( LogEvent::StudentEnrolled,   $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentFailed,  $handler );
		$this->logEvents->subscribe( LogEvent::StudentExpelled,   $handler );
		$this->logEvents->subscribe( LogEvent::StudentRestored,   $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentStarted, $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentCanceled, $handler );
	}

	/**
	 * Обработчик события, связанного с зачислением.
	 * Записывает в лог действие, тип цели (student_record), ID записи и контекст.
	 *
	 * @param EnrollmentStatusEvent $event Событие изменения статуса зачисления
	 *
	 * @return void
	 */
	public function handle( EnrollmentStatusEvent $event ): void {
		$this->writer->record(
			$event->action->value,
			'student_record',
			$event->studentRecordId,
			array_filter( array(
				'student_person_id' => $event->studentPersonId,
				'group_id'          => $event->groupId,
			) )
		);
	}
}