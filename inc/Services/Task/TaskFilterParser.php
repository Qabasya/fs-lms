<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskFilterParser
 *
 * Разбирает карту фильтров тренажёра `filters[<taxonomy>][]=<term>`.
 *
 * @package Inc\Services\Task
 *
 * ### Архитектурная роль:
 *
 * Один формат на три входа: AJAX-подгрузка списка (POST), ссылка-чип со
 * страницы задания (GET) и первичный рендер раздела «Тренажёр» на лендинге
 * предмета. Раньше разбор жил только в коллбеке — страница лендинга
 * повторила бы его слово в слово.
 */
readonly class TaskFilterParser {

	use Sanitizer;

	/**
	 * Фильтры запроса: [taxonomy_slug => term_slugs]. Неизвестные таксономии
	 * отсеивает уже билдер (он знает состав таксономий предмета).
	 *
	 * @param string $source Источник данных: 'POST' (AJAX) или 'GET' (ссылка-фильтр).
	 *
	 * @return array<string, string[]>
	 */
	public function fromRequest( string $source = 'POST' ): array {
		$raw = $this->unslashArray( 'filters', $source );

		$result = array();

		foreach ( $raw as $taxonomy => $slugs ) {
			$tax = $this->sanitizeKeyValue( $taxonomy );

			if ( '' === $tax || ! is_array( $slugs ) ) {
				continue;
			}

			$clean = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );

			if ( ! empty( $clean ) ) {
				$result[ $tax ] = $clean;
			}
		}

		return $result;
	}
}