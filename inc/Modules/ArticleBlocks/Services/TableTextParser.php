<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

/**
 * Class TableTextParser
 *
 * Разбирает текст поля «Таблица» в строки и ячейки.
 *
 * @package Inc\Modules\ArticleBlocks\Services
 *
 * ### Форматы
 *
 * - **Диапазон из LibreOffice Calc / Excel** — строки через перевод строки, ячейки через Tab.
 *   Основной сценарий: выделил ячейки, скопировал, вставил в поле.
 * - **Строки через `|`** — если набирать руками; крайние `|` и строка-разделитель
 *   Markdown (`---|---`) пропускаются.
 * - Без Tab и `|` каждая строка — одна ячейка.
 *
 * Пустые строки пропускаются, короткие строки дополняются пустыми ячейками до ширины таблицы.
 */
final readonly class TableTextParser {

	/** Строка-разделитель Markdown: `---|:---:|---`. */
	private const MARKDOWN_SEPARATOR = '/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?$/';

	/**
	 * @param string $text Текст поля (переводы строк — `\n`)
	 *
	 * @return array<int, array<int, string>> Строки таблицы
	 */
	public function parse( string $text ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $text ) ?: array();

		$delimiter = $this->delimiter( $lines );
		$rows      = array();

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			if ( '|' === $delimiter ) {
				$line = trim( $line );

				if ( 1 === preg_match( self::MARKDOWN_SEPARATOR, $line ) ) {
					continue;
				}

				$line = (string) preg_replace( '/^\||\|$/', '', $line );
			}

			$cells  = null === $delimiter ? array( $line ) : explode( $delimiter, $line );
			$rows[] = array_map( 'trim', $cells );
		}

		$width = array() === $rows ? 0 : max( array_map( 'count', $rows ) );

		return array_map(
			static fn( array $row ): array => array_pad( $row, $width, '' ),
			$rows
		);
	}

	/**
	 * Разделитель ячеек: Tab важнее `|` — в данных из таблицы `|` может быть содержимым ячейки.
	 *
	 * @param string[] $lines Строки текста
	 *
	 * @return string|null null — одна ячейка в строке
	 */
	private function delimiter( array $lines ): ?string {
		$joined = implode( "\n", $lines );

		if ( str_contains( $joined, "\t" ) ) {
			return "\t";
		}

		return str_contains( $joined, '|' ) ? '|' : null;
	}
}
