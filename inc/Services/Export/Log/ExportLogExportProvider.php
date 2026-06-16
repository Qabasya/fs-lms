<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Enums\ExportActionType;
use Inc\Enums\ExportTarget;
use Inc\Repositories\WPDBRepositories\Log\ExportLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class ExportLogExportProvider
 *
 * Провайдер экспорта журнала экспорта данных (export_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога экспорта.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных ExportLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата экспорта (форматированная)
 * - Пользователь, выполнивший экспорт, и его роль
 * - Тип данных (groups, students, parents, archive, log_*)
 * - Тип действия (single — единичный, bulk — массовый)
 * - ID экспортированных целей (JSON-массив)
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, data_type, actor_user_id) передаётся через контекст.
 * - Имена пользователей резолвятся через LogNameResolver.
 * - Лог экспорта важен для аудита и отслеживания выгрузок персональных данных.
 * - Поле targetIdsJson может быть NULL для массовых экспортов без указания конкретных ID.
 */
class ExportLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param ExportLogRepository $repository Репозиторий журнала экспорта
	 */
	public function __construct(
		private readonly ExportLogRepository $repository,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',         fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Тип данных',   fn( $r ) => ExportTarget::tryFrom( $r->dataType )?->label() ?? $r->dataType ),
			new CsvColumn( 'Тип действия', fn( $r ) => ExportActionType::tryFrom( $r->actionType )?->label() ?? $r->actionType ),
			new CsvColumn( 'Цели',         fn( $r ) => LogNameResolver::exportTargets( $r->dataType, $r->targetIdsJson, 0 ) ),
			new CsvColumn( 'IP',           fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство',   fn( $r ) => $r->actorUa ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, data_type, actor_user_id).
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
		return 'export-log';
	}
}