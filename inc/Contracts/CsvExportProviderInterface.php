<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Export\CsvColumn;

/**
 * Стратегия CSV-экспорта одного датасета.
 *
 * columns($context) — описание колонок (заголовки + closures). Контекст тот же,
 *                     что и у rows(): набор колонок может от него зависеть
 *                     (напр. `include_passwords` скрывает колонку пароля).
 * rows($context) — iterable строк; для доменных провайдеров $context['ids'],
 *                  для лог-провайдеров $context = массив фильтров.
 * filename() — имя файла без даты.
 */
interface CsvExportProviderInterface {

	/**
	 * @param array<string, mixed> $context Контекст экспорта (тот же, что в rows()).
	 *
	 * @return CsvColumn[]
	 */
	public function columns( array $context = array() ): array;

	/** @return iterable<mixed> */
	public function rows( array $context ): iterable;

	public function filename(): string;
}
