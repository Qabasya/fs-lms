<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\CsvColumn;

/**
 * Class CsvExportService
 *
 * Сервис генерации CSV и создания одноразовых ссылок для скачивания.
 *
 * ### Паттерн: Column Projection
 *
 * Сервис ничего не знает о доменных объектах. Вызывающий код передаёт:
 * - $rows    — любой iterable (массив массивов, массив DTO, генератор)
 * - $columns — массив CsvColumn, каждый с заголовком и closure-экстрактором
 *
 * Пример использования:
 *
 *   $csv = $service->export( $enrollments, [
 *       new CsvColumn( 'ФИО',      fn( $r ) => $r['student_name'] ),
 *       new CsvColumn( 'Телефон',  fn( $r ) => $r['phone'] ?? '' ),
 *       new CsvColumn( 'Предмет',  fn( $r ) => $r['subject'] ),
 *   ] );
 *   $url = $service->createDownloadLink( $csv, 'students.csv' );
 *
 * ### Формат CSV
 *
 * - Кодировка UTF-8 с BOM (для корректного открытия в Excel)
 * - Разделитель: запятая
 * - Строки заключаются в кавычки при необходимости (стандарт PHP fputcsv)
 */
class CsvExportService {

	/**
	 * Генерирует CSV-строку из произвольных данных.
	 *
	 * @param iterable   $rows    Строки данных — любой iterable
	 * @param CsvColumn[] $columns Описание колонок (заголовок + экстрактор)
	 *
	 * @return string CSV с BOM, готовый к отдаче клиенту
	 */
	public function export( iterable $rows, array $columns ): string {
		$handle = fopen( 'php://temp', 'r+' );

		// Заголовки
		fputcsv( $handle, array_map( fn( CsvColumn $c ) => $c->header, $columns ) );

		// Строки данных
		foreach ( $rows as $row ) {
			$cells = array_map( fn( CsvColumn $c ) => (string) ( ( $c->extractor )( $row ) ?? '' ), $columns );
			fputcsv( $handle, $cells );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		// UTF-8 BOM — необходим для корректного открытия в Microsoft Excel
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Сохраняет CSV во временный файл и возвращает одноразовый URL для скачивания.
	 * Файл и токен живут 1 час; после первого скачивания удаляются автоматически
	 * обработчиком PiiController::handleExportDownload().
	 *
	 * @param string $csv      CSV-строка из export()
	 * @param string $filename Имя файла для заголовка Content-Disposition
	 *
	 * @return string Одноразовый URL вида /lms/export/{token}
	 */
	public function createDownloadLink( string $csv, string $filename = 'export.csv' ): string {
		$token     = wp_generate_password( 32, false );
		$uploadDir = wp_upload_dir();
		$dir       = $uploadDir['basedir'] . '/lms-exports/';

		wp_mkdir_p( $dir );

		$path = $dir . $token . '.csv';
		file_put_contents( $path, $csv );

		set_transient( 'fs_lms_export_' . $token, array(
			'file'         => $path,
			'filename'     => $filename,
			'content_type' => 'text/csv; charset=utf-8',
		), HOUR_IN_SECONDS );

		return home_url( '/lms/export/' . $token );
	}
}
