<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Value-object для описания одной колонки CSV-экспорта.
 *
 * Передаётся в CsvExportService::export() в виде массива.
 * Closure $extractor получает одну строку данных и возвращает скалярное значение.
 *
 * Пример:
 *   new CsvColumn( 'ФИО',     fn( $row ) => $row['student_name'] )
 *   new CsvColumn( 'Телефон', fn( $row ) => $row['phone'] ?? '' )
 */
readonly class CsvColumn {

	/**
	 * @param string   $header    Заголовок колонки
	 * @param \Closure $extractor Функция извлечения значения из строки данных
	 */
	public function __construct(
		public string   $header,
		public \Closure $extractor,
	) {}
}
