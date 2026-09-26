<?php

declare( strict_types=1 );

namespace Inc\Services\Print;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Class DocxTemplateRenderer
 *
 * Подстановка полей `{{ключ}}` в DOCX-шаблон средствами PHP (ZipArchive + DOM).
 *
 * ### Разорванные поля
 *
 * Word режет текст абзаца на фрагменты (`<w:r><w:t>`) по своим причинам —
 * проверка орфографии, история правок, смена форматирования, — поэтому
 * `{{parent_full_name}}` в XML нередко лежит в трёх-четырёх фрагментах.
 * Рендерер склеивает текст абзаца, находит поля в склейке и пишет значение в
 * первый фрагмент поля (значение наследует его форматирование), а занятые
 * полем куски остальных фрагментов вычищает.
 *
 * Обрабатываются основной текст, колонтитулы и сноски.
 *
 * @package Inc\Services\Print
 */
class DocxTemplateRenderer {

	/**
	 * Поле шаблона: `{{ключ}}`, пробелы внутри скобок допустимы.
	 */
	private const string PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/';

	/**
	 * Части пакета, в которых ищутся поля.
	 */
	private const string PARTS = '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#';

	private const string NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * Ключи полей, встречающихся в шаблоне.
	 *
	 * @param string $path Путь к DOCX
	 *
	 * @return string[]
	 */
	public function placeholders( string $path ): array {
		$keys = array();

		$this->eachPart( $path, function ( DOMDocument $dom ) use ( &$keys ): bool {
			foreach ( $this->paragraphs( $dom ) as $paragraph ) {
				preg_match_all( self::PATTERN, $this->paragraphText( $paragraph ), $m );
				array_push( $keys, ...$m[1] );
			}
			return false;
		} );

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Собирает документ: копия шаблона с подставленными значениями.
	 *
	 * @param string                $path   Путь к DOCX-шаблону
	 * @param array<string, string> $values Значения по ключам полей
	 *
	 * @return string Содержимое готового DOCX
	 */
	public function render( string $path, array $values ): string {
		$tmp = wp_tempnam( 'fs-lms-print' );
		if ( ! copy( $path, $tmp ) ) {
			throw new RuntimeException( 'Не удалось подготовить файл документа.' );
		}

		try {
			$this->eachPart( $tmp, function ( DOMDocument $dom ) use ( $values ): bool {
				$changed = false;
				foreach ( $this->paragraphs( $dom ) as $paragraph ) {
					$changed = $this->fillParagraph( $paragraph, $values ) || $changed;
				}
				return $changed;
			} );

			$content = file_get_contents( $tmp );
		} finally {
			wp_delete_file( $tmp );
		}

		if ( false === $content ) {
			throw new RuntimeException( 'Не удалось прочитать готовый документ.' );
		}

		return $content;
	}

	/**
	 * Обходит XML-части пакета; если колбэк вернул true — часть перезаписывается.
	 *
	 * @param string                        $path     Путь к DOCX
	 * @param callable(DOMDocument): bool   $callback Обработчик части
	 */
	private function eachPart( string $path, callable $callback ): void {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'Шаблон документа повреждён: это не DOCX-файл.' );
		}

		try {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( ! preg_match( self::PARTS, $name ) ) {
					continue;
				}

				$xml = $zip->getFromIndex( $i );
				$dom = new DOMDocument();
				if ( false === $xml || ! $dom->loadXML( $xml, LIBXML_NONET ) ) {
					throw new RuntimeException( "Шаблон документа повреждён: не читается {$name}." );
				}

				if ( $callback( $dom ) ) {
					$zip->addFromString( $name, (string) $dom->saveXML() );
				}
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Абзацы части (включая абзацы внутри таблиц и надписей).
	 *
	 * @return DOMElement[]
	 */
	private function paragraphs( DOMDocument $dom ): array {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::NS_W );

		$nodes = $xpath->query( '//w:p' );

		return false === $nodes ? array() : iterator_to_array( $nodes );
	}

	/**
	 * Текстовые фрагменты абзаца по порядку.
	 *
	 * Вложенные абзацы (надпись внутри абзаца) обрабатываются отдельно,
	 * поэтому их фрагменты сюда не попадают.
	 *
	 * @return DOMElement[]
	 */
	private function textNodes( DOMElement $paragraph ): array {
		$nodes = array();
		foreach ( $paragraph->getElementsByTagNameNS( self::NS_W, 't' ) as $t ) {
			if ( $this->ownParagraph( $t ) === $paragraph ) {
				$nodes[] = $t;
			}
		}

		return $nodes;
	}

	private function ownParagraph( DOMElement $node ): ?DOMElement {
		for ( $p = $node->parentNode; null !== $p; $p = $p->parentNode ) {
			if ( $p instanceof DOMElement && 'p' === $p->localName && self::NS_W === $p->namespaceURI ) {
				return $p;
			}
		}

		return null;
	}

	private function paragraphText( DOMElement $paragraph ): string {
		return implode( '', array_map( static fn( DOMElement $t ): string => $t->textContent, $this->textNodes( $paragraph ) ) );
	}

	/**
	 * Подставляет значения в абзац.
	 *
	 * @param array<string, string> $values
	 *
	 * @return bool Абзац изменён
	 */
	private function fillParagraph( DOMElement $paragraph, array $values ): bool {
		$nodes = $this->textNodes( $paragraph );
		$orig  = array_map( static fn( DOMElement $t ): string => $t->textContent, $nodes );
		$texts = $orig;
		$full  = implode( '', $orig );

		if ( ! preg_match_all( self::PATTERN, $full, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return false;
		}

		// Смещение начала каждого фрагмента в склейке (в байтах — как у PREG_OFFSET_CAPTURE).
		$starts = array();
		$pos    = 0;
		foreach ( $orig as $i => $text ) {
			$starts[ $i ] = $pos;
			$pos         += strlen( $text );
		}

		// С конца: правка поля не сдвигает смещения полей левее. Границы фрагментов
		// ищутся по исходным длинам — правые фрагменты к этому моменту уже изменены.
		foreach ( array_reverse( $matches ) as $match ) {
			$begin = $match[0][1];
			$end   = $begin + strlen( $match[0][0] );
			$value = $values[ $match[1][0] ] ?? '';

			$first = $this->nodeAt( $starts, $orig, $begin );
			$last  = $this->nodeAt( $starts, $orig, $end - 1 );

			$head = substr( $texts[ $first ], 0, $begin - $starts[ $first ] );
			$tail = substr( $texts[ $last ], $end - $starts[ $last ] );

			if ( $first === $last ) {
				$texts[ $first ] = $head . $value . $tail;
			} else {
				$texts[ $first ] = $head . $value;
				for ( $i = $first + 1; $i < $last; $i++ ) {
					$texts[ $i ] = '';
				}
				$texts[ $last ] = $tail;
			}
		}

		foreach ( $nodes as $i => $node ) {
			if ( $orig[ $i ] === $texts[ $i ] ) {
				continue;
			}
			$node->textContent = $texts[ $i ];
			// Без preserve Word съедает пробелы на краях фрагмента.
			$node->setAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve' );
		}

		return true;
	}

	/**
	 * Индекс фрагмента, в который попадает смещение.
	 *
	 * @param int[]    $starts
	 * @param string[] $texts
	 */
	private function nodeAt( array $starts, array $texts, int $offset ): int {
		foreach ( $starts as $i => $start ) {
			if ( $offset >= $start && $offset < $start + strlen( $texts[ $i ] ) ) {
				return $i;
			}
		}

		return array_key_last( $starts );
	}
}
