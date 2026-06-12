<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\DeletionLogWriter;

/**
 * Class DeletionSubscriber
 *
 * Подписчик на события физического удаления сущностей. Записывает удаления в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события** — регистрация обработчика на событие физического удаления сущности.
 * 2. **Запись в лог** — при получении события вызывает DeletionLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Использует LogEventDispatcherInterface для подписки и DeletionLogWriter для записи.
 * Делает систему логирования расширяемой и слабосвязанной.
 *
 * ### Примечания:
 *
 * - Подписывается на событие EntityHardDeleted (физическое удаление из БД).
 * - Записывает в лог информацию о том, какая сущность была удалена и какие
 *   каскадные действия были выполнены (cascadedSummary).
 */
class DeletionSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $logEvents Диспетчер событий логирования
	 * @param DeletionLogWriter           $writer    Райтер для записи логов удалений
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly DeletionLogWriter           $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		$handler = array( $this, 'handle' );
		$this->logEvents->subscribe( LogEvent::EntityHardDeleted, $handler );
		$this->logEvents->subscribe( LogEvent::PersonSoftDeleted, $handler );
	}

	/**
	 * Обработчик события физического удаления сущности.
	 * Записывает информацию об удалении в лог (тип сущности, ID, каскадный дайджест).
	 *
	 * @param EntityHardDeletedEvent $event Событие удаления сущности
	 *
	 * @return void
	 */
	public function handle( EntityHardDeletedEvent $event ): void {
		$this->writer->record( $event->entityType, $event->entityId, $event->cascadedSummary );
	}
}