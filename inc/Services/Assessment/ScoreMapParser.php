<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

/**
 * Class ScoreMapParser
 *
 * Парсит таблицу перевода первичный→вторичный из текста (T7.16).
 * Принимает двухколоночный текст из Excel/Word/clipboard:
 * строки разделены переносом, пары — табом/пробелами/точкой с запятой/запятой.
 * Нечисловые строки-заголовки игнорируются.
 *
 * @package Inc\Services\Assessment
 */
class ScoreMapParser {

	/**
	 * @param  string $text Сырой текст из буфера обмена.
	 * @return array<int, int> primary => secondary
	 */
	public function parse( string $text ): array {
		$map  = [];
		$rows = preg_split( '/\r\n|\r|\n/', $text );
		if ( ! $rows ) {
			return $map;
		}

		foreach ( $rows as $row ) {
			$row = trim( $row );
			if ( '' === $row ) {
				continue;
			}

			// Разделители: таб, ; или несколько пробелов.
			$parts = preg_split( '/\t|;|,|\s{2,}/', $row, 2 );
			if ( ! $parts || count( $parts ) < 2 ) {
				continue;
			}

			$primary   = trim( $parts[0] );
			$secondary = trim( $parts[1] );

			// Пропускаем нечисловые строки-заголовки.
			if ( ! is_numeric( $primary ) || ! is_numeric( $secondary ) ) {
				continue;
			}

			$map[ (int) $primary ] = (int) $secondary;
		}

		ksort( $map );
		return $map;
	}
}
