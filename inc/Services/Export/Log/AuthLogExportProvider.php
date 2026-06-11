<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\AuthLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class AuthLogExportProvider
 *
 * Провайдер экспорта журнала аутентификации (auth_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога аутентификации.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных AuthLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата события (форматированная)
 * - Логин/email пользователя
 * - Действие (login, login_failed, password_reset)
 * - Результат (success/failure)
 * - IP-адрес пользователя
 * - User-Agent устройства
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, действию, результату) передаётся через контекст.
 * - Даты форматируются через LogNameResolver::date().
 */
class AuthLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param AuthLogRepository $repository Репозиторий журнала аутентификации
	 */
	public function __construct(
		private readonly AuthLogRepository $repository,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID',        fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',      fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Логин',     fn( $r ) => $r->loginIdentifier ?? '' ),
			new CsvColumn( 'Действие',  fn( $r ) => $r->action ),
			new CsvColumn( 'Результат', fn( $r ) => $r->result ),
			new CsvColumn( 'IP',        fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство', fn( $r ) => $r->actorUa ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, action, result).
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
		return 'auth-log';
	}
}