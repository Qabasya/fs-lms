<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class ConditionHtmlNormalizer
 *
 * Срезает «пустые строки» и невидимый мусор в HTML условия задания.
 *
 * @package Inc\Services\Task
 *
 * Условия приезжают из Word/Google Docs и легаси-импорта: хвостовые
 * `<p>&nbsp;</p>`, `<br>` в конце, абзацы из одних неразрывных пробелов,
 * BOM и zero-width символы. В тренажёре, курсе и контрольной это давало
 * пустые строки под условием. Нормализатор:
 *
 * - удаляет BOM, zero-width символы и управляющие символы (кроме `\t`, `\n`,
 *   `\r` — переводы строк редактор WordPress хранит как `\r\n`);
 * - срезает пустые блоки и `<br>` в начале и в конце;
 * - три и больше пустых абзаца `<p>` подряд внутри текста схлопывает в один —
 *   одиночную отбивку автор мог поставить намеренно;
 * - пробелы внутри формул вида `F = x + y - z` делает неразрывными, чтобы формула
 *   при нехватке места переносилась целиком, а не рвалась на знаке. Текст внутри
 *   `<pre>`, `<code>`, `<script>`, `<style>` и формулы MathJax (`\(…\)`, `\[…\]`) не трогается.
 *
 * Работает и с сырым значением поля (абзацы — пустые строки, `wpautop` ещё
 * не прошёл), и с готовым HTML. Пустые строки внутри `<pre>` не трогаются:
 * срез идёт только по краям условия, а схлопываются только блоки `<p>`,
 * которых внутри кода не бывает.
 */
readonly class ConditionHtmlNormalizer {

	/** Пустой «атом»: пробел, неразрывный пробел (сущностью или символом), перевод строки. */
	private const BLANK = '(?:\s|&nbsp;|&#160;|&#x0*a0;|\x{00A0}|<br\s*\/?>)';

	/** Пустой блок: абзац/див/спан без видимого содержимого (без групп захвата — см. `$1` в normalize()). */
	private const EMPTY_BLOCK = '(?:<p\b[^>]*>' . self::BLANK . '*<\/p>|<div\b[^>]*>' . self::BLANK . '*<\/div>|<span\b[^>]*>' . self::BLANK . '*<\/span>)';

	/** Невидимые символы: BOM, zero-width, word joiner, управляющие (кроме \t и \n). */
	private const INVISIBLE = '/[\x{FEFF}\x{200B}-\x{200D}\x{2060}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u';

	/** Знак операции, сравнения или алгебры логики (¬ ∧ ∨ → ↔ ⊕ ≡ ↑ ↓ и т. п.) (в тексте `<` и `>` приходят сущностями). */
	private const OPERATOR = '(?:&lt;|&gt;|&le;|&ge;|&ne;|[=+\-\x{2212}*\/%^\x{00AC}\x{00D7}\x{00F7}\x{2264}\x{2265}\x{2260}\x{2248}\x{2261}\x{2192}\x{21D2}\x{2194}\x{21D4}\x{2227}\x{2228}\x{2295}\x{22BB}\x{22BC}\x{22BD}\x{2191}\x{2193}\x{223C}\x{27F6}\x{27F7}\x{27F9}\x{27FA}\x{2283}\x{2282}\x{2299}\x{22C5}\x{2219}\x{00B7}\x{2223}|~\\\\])';

	/**
	 * Токен формулы: цепочка символов (знаки, скобки, запятые), цифр и одиночных латинских
	 * переменных (`x`, `y2`, `F`). Слово из двух и более букв токеном не является — так проза
	 * не попадает в формулу. Токен ограничен пробелами (в том числе уже неразрывными) или краями текста.
	 */
	private const TOKEN = '(?<![^\s\x{00A0}])(?:&(?:lt|gt|le|ge|ne);|[^\p{L}\p{N}\s\x{00A0}<>&]|\d+|[A-Za-z]\d*(?![A-Za-z]))+(?![^\s\x{00A0}])';

	/** Теги, текст внутри которых остаётся как есть. */
	private const VERBATIM_TAGS = array( 'pre', 'code', 'script', 'style' );

	/**
	 * @param string $html Условие (сырое значение поля или HTML после `wpautop`/`the_content`)
	 *
	 * @return string Условие без пустых строк по краям и невидимого мусора
	 */
	public function normalize( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		// Переводы строк \r\n не трогаем: так условие хранит сам редактор WordPress.
		$html = (string) preg_replace( self::INVISIBLE, '', $html );

		$edge  = '(?:' . self::BLANK . '|' . self::EMPTY_BLOCK . ')+';
		$open  = '((?:\s*<(?:div|section)\b[^>]*>)*)';
		$close = '((?:\s*<\/(?:div|section)>)*\s*)';

		// Край за краем. Пустышки внутри обёртки (`<div><p>&nbsp;</p></div>`)
		// срезаются у её внутреннего края; опустевшая обёртка уйдёт на следующем круге.
		do {
			$before = $html;
			$html   = (string) preg_replace( '/^' . $open . $edge . '/iu', '$1', $html );
			$html   = (string) preg_replace( '/' . $edge . $close . '$/iu', '$1', $html );
		} while ( $html !== $before );

		// Три и больше пустых абзаца подряд внутри текста → один.
		$html = (string) preg_replace(
			'/(?:<p\b[^>]*>(?:\s|&nbsp;|&#160;|&#x0*a0;|\x{00A0}|<br\s*\/?>)*<\/p>\s*){3,}/iu',
			"<p>&nbsp;</p>\n",
			$html
		);

		return trim( $this->glueFormulas( $html ) );
	}

	/**
	 * Склеивает пробелы в формулах неразрывными, обходя разметку, `<pre>`/`<code>` и MathJax.
	 */
	private function glueFormulas( string $html ): string {
		$parts   = preg_split( '/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $html );
		$verbose = 0;

		foreach ( $parts as $i => $part ) {
			if ( str_starts_with( $part, '<' ) ) {
				$verbose = max( 0, $verbose + $this->verbatimDelta( $part ) );
				continue;
			}

			if ( 0 === $verbose && '' !== $part ) {
				$parts[ $i ] = $this->glueText( $part );
			}
		}

		return implode( '', $parts );
	}

	/** +1 на открытии `<pre>`/`<code>`/…, -1 на закрытии, 0 на прочих тегах. */
	private function verbatimDelta( string $tag ): int {
		if ( ! preg_match( '/^<(\/?)([a-z0-9]+)/i', $tag, $m ) || ! in_array( strtolower( $m[2] ), self::VERBATIM_TAGS, true ) ) {
			return 0;
		}

		return '/' === $m[1] ? -1 : 1;
	}

	/** Текстовый узел: формулы MathJax пропускаются, остальное склеивается. */
	private function glueText( string $text ): string {
		$chunks = preg_split( '/(\\\\\(.*?\\\\\)|\\\\\[.*?\\\\\])/su', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $text );

		foreach ( $chunks as $i => $chunk ) {
			if ( 0 === $i % 2 ) {
				$chunks[ $i ] = $this->glueChunk( $chunk );
			}
		}

		return implode( '', $chunks );
	}

	/**
	 * Формула — подряд идущие токены формулы, среди которых есть знак операции.
	 * Пробелы между ними заменяются неразрывными.
	 */
	private function glueChunk( string $text ): string {
		$token   = self::TOKEN;
		$pattern = "/{$token}(?:[ \x{00A0}]+{$token})+/u";

		return (string) preg_replace_callback(
			$pattern,
			fn( array $m ): string => 1 === preg_match( '/' . self::OPERATOR . '/u', $m[0] )
				? (string) preg_replace( '/ +/', "\u{00A0}", $m[0] )
				: $m[0],
			$text
		);
	}
}
