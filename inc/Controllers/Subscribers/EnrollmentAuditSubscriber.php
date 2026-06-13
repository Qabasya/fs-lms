<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\AuditTargetType;
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
 * - EnrollmentStarted — процесс зачисления начат (application-level)
 * - EnrollmentCanceled — процесс зачисления отменён
 * - ApplicationCreated / ApplicationUpdated / ApplicationViewed — действия с заявкой
 * - ApplicationTrashed / ApplicationRestored — корзина
 * - ParentSigned / ApplicationExpired — события родителя и истечения
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
		$appHandler = array( $this, 'handleApplication' );

		$this->logEvents->subscribe( LogEvent::StudentEnrolled,    $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentFailed,   $handler );
		$this->logEvents->subscribe( LogEvent::StudentExpelled,    $handler );
		$this->logEvents->subscribe( LogEvent::StudentRestored,    $handler );
		$this->logEvents->subscribe( LogEvent::EnrollmentCanceled, $handler );

		$this->logEvents->subscribe( LogEvent::ApplicationCreated,  $appHandler );
		$this->logEvents->subscribe( LogEvent::ApplicationUpdated,  $appHandler );
		$this->logEvents->subscribe( LogEvent::ParentSigned,        $appHandler );
		$this->logEvents->subscribe( LogEvent::ApplicationExpired,  $appHandler );
		$this->logEvents->subscribe( LogEvent::ApplicationTrashed,  $appHandler );
		$this->logEvents->subscribe( LogEvent::ApplicationRestored, $appHandler );
		$this->logEvents->subscribe( LogEvent::ApplicationViewed,   $appHandler );
		$this->logEvents->subscribe( LogEvent::EnrollmentStarted,   $appHandler );
	}

	/**
	 * Обработчик события изменения статуса зачисления.
	 *
	 * @param EnrollmentStatusEvent $event
	 *
	 * @return void
	 */
	public function handle( EnrollmentStatusEvent $event ): void {
		$this->writer->record(
			$event->action,
			AuditTargetType::StudentRecord,
			$event->studentPersonId,
			array_filter( array(
				'student_record_id' => $event->studentRecordId,
				'group_id'          => $event->groupId,
			) )
		);
	}

	/**
	 * Обработчик события изменения статуса заявки.
	 *
	 * @param ApplicationStatusEvent $event
	 *
	 * @return void
	 */
	public function handleApplication( ApplicationStatusEvent $event ): void {
		$details = array_filter( array(
			'student_person_id' => $event->studentPersonId,
		) );

		if ( $event->actorUserId > 0 ) {
			$this->writer->record(
				$event->action,
				AuditTargetType::Application,
				$event->applicationId,
				$details ?: null,
			);
		} else {
			$this->writer->recordAnonymous(
				$event->action,
				AuditTargetType::Application,
				$event->applicationId,
				$details ?: null,
			);
		}
	}
}