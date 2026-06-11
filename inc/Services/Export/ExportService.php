<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Enums\ExportTarget;
use Inc\Services\CsvExportService;
use Inc\Services\Log\ExportLogWriter;

/**
 * Оркестратор CSV-экспорта. Фиксированный конвейер:
 *   resolve → rows → csv → log → download link
 *
 * Authorization — на стороне вызывающего AJAX-колбэка (Authorizer trait).
 */
readonly class ExportService {

	public function __construct(
		private CsvExportProviderRegistry $registry,
		private CsvExportService          $csvService,
		private ExportLogWriter           $exportLog,
	) {}

	/**
	 * @param ExportTarget $target   Целевой датасет
	 * @param array        $context  Для доменных: ['ids' => int[]]; для логов: массив фильтров
	 * @param string       $mode     'single' | 'bulk'
	 *
	 * @return string Одноразовая ссылка на скачивание
	 */
	public function run( ExportTarget $target, array $context = array(), string $mode = 'bulk' ): string {
		$provider = $this->registry->resolve( $target );

		$rows     = $provider->rows( $context );
		$columns  = $provider->columns();
		$filename = $provider->filename() . '-' . wp_date( 'Y-m-d' ) . '.csv';

		$csv = $this->csvService->export( $rows, $columns );
		$url = $this->csvService->createDownloadLink( $csv, $filename );

		$this->exportLog->record( $target->value, $mode );

		return $url;
	}
}
