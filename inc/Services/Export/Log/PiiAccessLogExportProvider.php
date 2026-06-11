<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class PiiAccessLogExportProvider
 *
 * Провайдер экспорта журнала доступа к персональным данным (pii_access_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога доступа к PII.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных PiiAccessLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата доступа (форматированная)
 * - Пользователь, запросивший доступ, и его роль
 * - Лицо (Person), чьи данные запрошены
 * - Список запрошенных полей (full_name, passport, inn, address, phone)
 * - Причина доступа
 * - IP-адрес пользователя
 * - User-Agent устройства
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, person_id, actor_user_id) передаётся через контекст.
 * - Имена пользователей и лиц резолвятся через LogNameResolver.
 * - Лог доступа к PII является неизменяемым и обязательным для compliance (152-ФЗ, GDPR).
 * - Обязательность поля accessReason гарантирует легитимность каждого доступа.
 */
class PiiAccessLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param PiiAccessLogRepository $repository Репозиторий журнала доступа к PII
	 */
	public function __construct(
		private readonly PiiAccessLogRepository $repository,
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
			new CsvColumn( 'Person',       fn( $r ) => LogNameResolver::personName( $r->personId ) ),
			new CsvColumn( 'Поля',         fn( $r ) => $r->fieldsAccessed ),
			new CsvColumn( 'Причина',      fn( $r ) => $r->accessReason ),
			new CsvColumn( 'IP',           fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство',   fn( $r ) => $r->actorUa ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, person_id, actor_user_id).
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
		return 'pii-access-log';
	}
}