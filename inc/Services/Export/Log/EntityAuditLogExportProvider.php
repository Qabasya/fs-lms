<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Repositories\WPDBRepositories\Log\EntityAuditLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class EntityAuditLogExportProvider
 *
 * Провайдер экспорта журнала аудита сущностей (entity_audit_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога аудита сущностей.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 * 3. **Резолвинг названий** — преобразование ID в человекочитаемые имена.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных EntityAuditLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата события (форматированная)
 * - Пользователь, выполнивший действие, и его роль
 * - Тип операции (create, update, delete)
 * - Тип сущности (subject, taxonomy, task, article, group, period, user)
 * - Название сущности (человекочитаемое)
 * - Прошлое название сущности (для операций удаления)
 * - IP-адрес пользователя
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, operation, entity_type, actor_user_id) передаётся через контекст.
 * - Названия сущностей резолвятся через LogNameResolver::entityName().
 * - Прошлое название (oldLabel) особенно важно для сущностей на основе опций
 *   (предметы, таксономии, boilerplate, периоды) — т.к. после удаления ID может быть утерян.
 */
class EntityAuditLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param EntityAuditLogRepository $repository Репозиторий журнала аудита сущностей
	 */
	public function __construct(
		private readonly EntityAuditLogRepository $repository,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID',              fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',            fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь',    fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',            fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Операция',        fn( $r ) => $r->operation->value ),
			new CsvColumn( 'Тип сущности',    fn( $r ) => $r->entityType->value ),
			new CsvColumn( 'Сущность',        fn( $r ) => LogNameResolver::entityName( $r->entityId, $r->entityType->value, $r->oldLabel ) ),
			new CsvColumn( 'Прошлое название', fn( $r ) => $r->oldLabel ?? '' ),
			new CsvColumn( 'IP',              fn( $r ) => $r->actorIp ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, operation, entity_type, actor_user_id).
	 *
	 * @param array $context Контекст экспорта (фильтры)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		// listAll() — возвращает все записи по фильтрам (без пагинации)
		return $this->repository->listAll( $context );
	}

	/**
	 * Возвращает базовое имя файла (без расширения).
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'entity-audit-log';
	}
}