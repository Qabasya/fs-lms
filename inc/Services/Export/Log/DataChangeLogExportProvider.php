<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Repositories\WPDBRepositories\Log\DataChangeLogRepository;
use Inc\Services\Log\LogNameResolver;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class DataChangeLogExportProvider
 *
 * Провайдер экспорта журнала изменений персональных данных (data_change_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога изменений данных.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 * 3. **Расшифровка значений** — дешифрование старых и новых значений полей.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных DataChangeLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата события (форматированная)
 * - Пользователь, изменивший данные, и его роль
 * - Лицо (Person), чьи данные изменены
 * - Название поля (doc_number, inn, address, phone и т.д.)
 * - Старое значение поля (расшифрованное)
 * - Новое значение поля (расшифрованное)
 *
 * ### Примечания:
 *
 * - Старые и новые значения хранятся в зашифрованном виде (BLOB).
 * - Расшифровка выполняется через PiiCryptoService.
 * - Фильтрация (по датам, полю, person_id) передаётся через контекст.
 * - Имена пользователей и лиц резолвятся через LogNameResolver.
 */
class DataChangeLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param DataChangeLogRepository $repository Репозиторий журнала изменений данных
	 * @param PiiCryptoService        $crypto     Сервис шифрования PII
	 */
	public function __construct(
		private readonly DataChangeLogRepository $repository,
		private readonly PiiCryptoService        $crypto,
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
			new CsvColumn( 'Person',          fn( $r ) => LogNameResolver::personName( $r->targetPersonId ) ),
			new CsvColumn( 'Поле',            fn( $r ) => $r->fieldName ),
			new CsvColumn( 'Старое значение', fn( $r ) => $this->decrypt( $r->oldValueEnc ) ),
			new CsvColumn( 'Новое значение',  fn( $r ) => $this->decrypt( $r->newValueEnc ) ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, field_name, target_person_id).
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
		return 'data-change-log';
	}

	/**
	 * Расшифровывает строку из зашифрованного BLOB.
	 *
	 * @param string|null $enc Зашифрованные данные
	 *
	 * @return string
	 */
	private function decrypt( ?string $enc ): string {
		if ( null === $enc || '' === $enc ) {
			return '';
		}
		try {
			return $this->crypto->decrypt( $enc );
		} catch ( \Throwable ) {
			return '';
		}
	}
}