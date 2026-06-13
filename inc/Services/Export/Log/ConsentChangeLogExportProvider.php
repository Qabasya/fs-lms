<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Repositories\WPDBRepositories\Log\ConsentChangeLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class ConsentChangeLogExportProvider
 *
 * Провайдер экспорта журнала изменений согласий (consent_change_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога изменений согласий.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных ConsentChangeLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата события (форматированная)
 * - Пользователь, изменивший согласие
 * - Лицо (Person), чьё согласие изменилось
 * - Тип согласия (pd_processing, marketing и т.д.)
 * - Старый хеш документа согласия
 * - Новый хеш документа согласия
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, person_id, consent_type) передаётся через контекст.
 * - Имена пользователей и лиц резолвятся через LogNameResolver.
 * - Хеши позволяют отследить, какая версия документа была заменена.
 */
class ConsentChangeLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param ConsentChangeLogRepository $repository Репозиторий журнала изменений согласий
	 */
	public function __construct(
		private readonly ConsentChangeLogRepository $repository,
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
			new CsvColumn( 'Person',       fn( $r ) => LogNameResolver::personName( $r->personId ) ),
			new CsvColumn( 'Тип согласия', fn( $r ) => $r->consentType ),
			new CsvColumn( 'Старый хеш',   fn( $r ) => $r->oldHash ?? '' ),
			new CsvColumn( 'Новый хеш',    fn( $r ) => $r->newHash ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, person_id, consent_type).
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
		return 'consent-change-log';
	}
}