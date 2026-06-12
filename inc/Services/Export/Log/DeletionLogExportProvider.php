<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Repositories\WPDBRepositories\Log\DeletionLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class DeletionLogExportProvider
 *
 * Провайдер экспорта журнала удалений сущностей (deletion_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога удалений.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных DeletionLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата события (форматированная)
 * - Пользователь, выполнивший удаление, и его роль
 * - Тип удалённой сущности (group, period, student, parent)
 * - ID удалённой сущности
 * - Описание каскадных удалений (JSON)
 * - IP-адрес пользователя
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, entity_type, actor_user_id) передаётся через контекст.
 * - Имена пользователей резолвятся через LogNameResolver.
 * - Каскадный дайджест (cascadedSummary) содержит информацию о связанных удалённых записях.
 * - Лог удалений важен для аудита и соответствия требованиям хранения данных.
 */
class DeletionLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param DeletionLogRepository $repository Репозиторий журнала удалений
	 */
	public function __construct(
		private readonly DeletionLogRepository $repository,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID',               fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',             fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь',     fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',             fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Тип сущности',     fn( $r ) => $r->entityType ),
			new CsvColumn( 'ID сущности',      fn( $r ) => $r->entityId ),
			new CsvColumn( 'Каскадно удалено', fn( $r ) => $r->cascadedSummary ?? '' ),
			new CsvColumn( 'IP',               fn( $r ) => $r->actorIp ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, entity_type, actor_user_id).
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
		return 'deletion-log';
	}
}