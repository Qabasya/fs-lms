<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

/**
 * Class Pluralizer
 *
 * Статическая утилита русского склонения по числу: 1 задание, 2–4 задания, 5+ заданий.
 * Единственный источник правила для PHP-стороны; JS-зеркало — `src/js/common/plural.js`.
 *
 * @package Inc\Services\Shared
 */
final class Pluralizer {

	/**
	 * Выбирает форму слова по числу.
	 *
	 * @param int    $n    Число.
	 * @param string $one  Форма для 1 (задание).
	 * @param string $few  Форма для 2–4 (задания).
	 * @param string $many Форма для 5+ и 11–14 (заданий).
	 *
	 * @return string
	 */
	public static function ru( int $n, string $one, string $few, string $many ): string {
		$m10  = abs( $n ) % 10;
		$m100 = abs( $n ) % 100;

		if ( 1 === $m10 && 11 !== $m100 ) {
			return $one;
		}

		if ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 10 || $m100 >= 20 ) ) {
			return $few;
		}

		return $many;
	}

	/**
	 * Число вместе с выбранной формой: «3 задания».
	 *
	 * @param int    $n    Число.
	 * @param string $one  Форма для 1.
	 * @param string $few  Форма для 2–4.
	 * @param string $many Форма для 5+.
	 *
	 * @return string
	 */
	public static function withNumber( int $n, string $one, string $few, string $many ): string {
		return $n . ' ' . self::ru( $n, $one, $few, $many );
	}
}