<?php
/**
 * CSV-выгрузка школьного отчёта (ученики × задания + первичный/вторичный балл и сводка).
 *
 * Формат повторяет таблицу страницы (и таблицу статистики проведения): колонка на каждое задание
 * формата (балл за задание, пусто — задание не решали), затем первичный и вторичный балл; внизу
 * «Средний» по окончательным результатам и построчная сводка по заданиям (попытки, верные решения,
 * процент попыток, процент верных). Разделитель «;» и BOM — чтобы русский Excel открыл файл сразу.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Services\Export\CsvExportService;

/**
 * CSV-выгрузка школьного отчёта по матрице {@see ExamReportViewService::matrix()}.
 *
 * @package Inc\Services\Exam
 */
class ExamReportCsvExport {

	public function __construct( private readonly CsvExportService $csv ) {}

	/**
	 * Собирает CSV по матрице отчёта (`columns`, `rows` с `cells`/`primary`/`secondary`, `footer`).
	 *
	 * @param array<string, mixed> $table Матрица {@see ExamReportViewService::matrix()}.
	 */
	public function build( array $table ): string {
		$columns = $table['columns'];
		$stream  = fopen( 'php://temp', 'r+' );

		fwrite( $stream, "\xEF\xBB\xBF" ); // BOM: Excel иначе читает UTF-8 CSV как ANSI.

		$this->line(
			$stream,
			array_merge( array( 'ФИО' ), $columns, array( 'Первичный балл', 'Вторичный балл' ) )
		);

		foreach ( $table['rows'] as $row ) {
			$cells = array();
			foreach ( $columns as $number ) {
				$cells[] = $row['cells'][ $number ]['score'] ?? '';
			}

			$this->line(
				$stream,
				array_merge( array( $row['name'] ), $cells, array( $row['primary'], $row['secondary'] ) )
			);
		}

		$footer = $table['footer'];

		$this->line(
			$stream,
			array_merge(
				array( 'Средний' ),
				array_fill( 0, count( $columns ), '' ),
				array( $footer['average_primary'], $footer['average_secondary'] )
			)
		);

		$summaryRow = static function ( string $label, array $values ): array {
			return array_merge( array( $label ), array_map( 'strval', $values ), array( '', '' ) );
		};

		$this->line( $stream, $summaryRow( 'Попытки', $footer['attempts'] ) );
		$this->line( $stream, $summaryRow( 'Верные решения', $footer['correct'] ) );
		$this->line( $stream, $summaryRow( 'Процент попыток, %', $footer['attempts_share'] ) );
		$this->line( $stream, $summaryRow( 'Процент верных, %', $footer['correct_share'] ) );

		rewind( $stream );

		return (string) stream_get_contents( $stream );
	}

	/**
	 * Пишет одну строку CSV (разделитель «;»).
	 *
	 * @param resource     $stream
	 * @param list<string> $fields
	 */
	private function line( $stream, array $fields ): void {
		fputcsv( $stream, array_map( fn( mixed $field ): string => (string) $this->csv->neutralizeFormula( $field ), $fields ), ';', '"', '' );
	}
}
