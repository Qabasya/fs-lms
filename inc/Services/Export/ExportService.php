<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Enums\ExportTarget;
use Inc\Services\Export\CsvExportService;
use Inc\Services\Log\ExportLogWriter;

/**
 * Class ExportService
 *
 * Оркестратор CSV-экспорта данных.
 *
 * @package Inc\Services\Export
 *
 * ### Основные обязанности:
 *
 * 1. **Оркестрация экспорта** — координация провайдера, CSV-сервиса и лога.
 * 2. **Генерация ссылки** — создание одноразовой ссылки для скачивания файла.
 * 3. **Логирование экспорта** — запись факта экспорта в журнал аудита.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Оркестратор (Orchestrator) для фиксированного конвейера:
 *
 * ```
 * resolve → rows → csv → log → download link
 * ```
 *
 * - resolve — получение провайдера из реестра по ExportTarget
 * - rows — получение данных от провайдера (итератор)
 * - csv — формирование CSV-файла
 * - log — запись в журнал экспорта
 * - download link — создание одноразовой ссылки
 *
 * ### Примечания:
 *
 * - Авторизация — на стороне вызывающего AJAX-коллбека (трейт Authorizer).
 * - Класс readonly — все свойства неизменяемы после инициализации.
 * - Метод run() возвращает одноразовую ссылку для скачивания.
 */
readonly class ExportService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param CsvExportProviderRegistry $registry   Реестр провайдеров CSV-экспорта
	 * @param CsvExportService          $csvService Сервис генерации CSV-файлов
	 * @param ExportLogWriter           $exportLog  Райтер для записи логов экспорта
	 */
	public function __construct(
		private CsvExportProviderRegistry $registry,
		private CsvExportService          $csvService,
		private ExportLogWriter           $exportLog,
	) {}

	/**
	 * Выполняет экспорт данных в CSV и возвращает одноразовую ссылку.
	 *
	 * @param ExportTarget $target  Целевой тип экспорта (groups, students, parents, archive, log_*)
	 * @param array        $context Контекст экспорта:
	 *                              - для доменных данных: ['ids' => int[]]
	 *                              - для логов: массив фильтров (date_from, date_to, action и т.д.)
	 * @param string       $mode    Тип экспорта: 'single' (один ID) или 'bulk' (массовый)
	 *
	 * @return string Одноразовая ссылка для скачивания CSV-файла
	 */
	public function run( ExportTarget $target, array $context = array(), string $mode = 'bulk' ): string {
		// Получение провайдера из реестра
		$provider = $this->registry->resolve( $target );

		// Получение данных и метаинформации от провайдера
		$rows     = $provider->rows( $context );      // Итератор строк
		$columns  = $provider->columns();              // Структура колонок
		$filename = $provider->filename() . '-' . wp_date( 'Y-m-d' ) . '.csv';

		// Генерация CSV-файла и получение ссылки для скачивания
		$csv = $this->csvService->export( $rows, $columns );
		$url = $this->csvService->createDownloadLink( $csv, $filename );

		// Логирование факта экспорта в журнал аудита
		$this->exportLog->record( $target->value, $mode );

		return $url;
	}
}