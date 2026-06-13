<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Export\CsvColumn;

/**
 * Стратегия CSV-экспорта одного датасета.
 *
 * columns() — описание колонок (заголовки + closures).
 * rows($context) — iterable строк; для доменных провайдеров $context['ids'],
 *                  для лог-провайдеров $context = массив фильтров.
 * filename() — имя файла без даты.
 */
interface CsvExportProviderInterface {

	/** @return CsvColumn[] */
	public function columns(): array;

	/** @return iterable<mixed> */
	public function rows( array $context ): iterable;

	public function filename(): string;
}
