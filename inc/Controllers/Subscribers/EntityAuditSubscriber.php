<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Services\Log\EntityAuditLogWriter;

/**
 * Class EntityAuditSubscriber
 *
 * Подписчик канала EntityAudit.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подписка на события сущностей** — регистрация обработчика на все entity-события шины.
 * 2. **Делегирование записи** — при получении события вызывает EntityAuditLogWriter для сохранения записи.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Observer (наблюдатель) для событийной системы логирования.
 * Содержит только маппинг событие → writer (без бизнес-логики).
 * Использует LogEventDispatcherInterface для подписки и EntityAuditLogWriter для записи.
 *
 * ### Отслеживаемые сущности:
 *
 * - **Предметы** — создание, обновление, удаление
 * - **Таксономии** — создание, обновление, удаление
 * - **Шаблоны** — создание, обновление, удаление
 * - **Boilerplate** — создание, обновление, удаление
 * - **Задания** — создание, обновление, удаление
 * - **Статьи** — создание, обновление, удаление
 * - **Группы** — создание, обновление, удаление
 * - **Периоды** — создание, обновление, удаление
 * - **Пользователи** — создание, обновление, удаление
 */
class EntityAuditSubscriber implements ServiceInterface {

	/**
	 * Конструктор подписчика.
	 *
	 * @param LogEventDispatcherInterface $dispatcher Диспетчер событий логирования
	 * @param EntityAuditLogWriter        $writer     Райтер для записи логов изменений сущностей
	 */
	public function __construct(
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly EntityAuditLogWriter        $writer,
	) {}

	/**
	 * Регистрирует все хуки и подписки на события.
	 *
	 * @return void
	 */
	public function register(): void {
		// Список всех entity-событий, за которыми следим
		$entityEvents = array(
			// Предметы
			LogEvent::SubjectCreated,
			LogEvent::SubjectUpdated,
			LogEvent::SubjectDeleted,
			// Таксономии
			LogEvent::TaxonomyCreated,
			LogEvent::TaxonomyUpdated,
			LogEvent::TaxonomyDeleted,
			// Термы
			LogEvent::TermCreated,
			LogEvent::TermUpdated,
			LogEvent::TermDeleted,
			// Шаблоны
			LogEvent::TemplateCreated,
			LogEvent::TemplateUpdated,
			LogEvent::TemplateDeleted,
			// Boilerplate
			LogEvent::BoilerplateCreated,
			LogEvent::BoilerplateUpdated,
			LogEvent::BoilerplateDeleted,
			// Задания
			LogEvent::TaskCreated,
			LogEvent::TaskUpdated,
			LogEvent::TaskDeleted,
			// Статьи
			LogEvent::ArticleCreated,
			LogEvent::ArticleUpdated,
			LogEvent::ArticleDeleted,
			// Группы
			LogEvent::GroupCreated,
			LogEvent::GroupUpdated,
			LogEvent::GroupDeleted,
			// Периоды
			LogEvent::PeriodCreated,
			LogEvent::PeriodUpdated,
			LogEvent::PeriodDeleted,
			// Пользователи
			LogEvent::UserCreated,
			LogEvent::UserUpdated,
			LogEvent::UserDeleted,
			// Импорт CSV (сводка файла)
			LogEvent::CsvImported,
		);

		// Подписка на каждое событие с одним обработчиком
		foreach ( $entityEvents as $event ) {
			$this->dispatcher->subscribe( $event, array( $this, 'handle' ) );
		}
	}

	/**
	 * Обработчик события изменения сущности.
	 * Записывает в лог ID пользователя, операцию, тип сущности, ID сущности и старое название.
	 *
	 * @param EntityChangedEvent $payload Событие изменения сущности
	 *
	 * @return void
	 */
	public function handle( EntityChangedEvent $payload ): void {
		$this->writer->record(
			$payload->actorUserId,
			$payload->operation,
			$payload->entityType,
			$payload->entityId,
			$payload->oldLabel,
		);
	}
}