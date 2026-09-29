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
 *   одиночную отбивку автор мог поставить намеренно.
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

		return trim( $html );
	}
}
