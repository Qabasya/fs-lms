<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskFilterParser
 *
 * Разбирает и собирает карту фильтров `filters[<taxonomy>][]=<term>`
 * (тренажёр и учебник).
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

	/**
	 * Ссылка на раздел (тренажёр, учебник) с предвыбранным фильтром.
	 *
	 * Обратная сторона fromRequest(): страница разбирает параметр на SSR
	 * и отмечает опцию в сайдбаре как активную.
	 *
	 * @param string $section_url Ссылка на раздел предмета.
	 * @param string $taxonomy    Слаг таксономии.
	 * @param string $term_slug   Слаг термина.
	 *
	 * @return string Пустая строка, если раздел или термин неизвестны.
	 */
	public function url( string $section_url, string $taxonomy, string $term_slug ): string {
		if ( '' === $section_url || '' === $taxonomy || '' === $term_slug ) {
			return '';
		}

		return add_query_arg( array( 'filters' => array( $taxonomy => array( $term_slug ) ) ), $section_url );
	}
}