<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class LatexPageShortcodeService
 *
 * Дописывает `[latexpage]` в условие задания, где есть формула.
 *
 * @package Inc\Services\Task
 *
 * QuickLaTeX разбирает `$…$` / `$$…$$` только на страницах, помеченных
 * шорткодом `[latexpage]`. Шорткод относится к СТРАНИЦЕ целиком, а не к
 * отдельной формуле, поэтому кнопка «Вставить формулу» его не пишет: иначе в
 * условии с тремя формулами оказалось бы три шорткода. Вместо этого он
 * добавляется один раз здесь — при сохранении поля.
 *
 * Сервис намеренно ничего не знает про сам QuickLaTeX и не проверяет, включён
 * ли плагин: без него `[latexpage]` остаётся незарегистрированным шорткодом и
 * WordPress печатает его как текст — поэтому шорткод и дописывается только
 * тогда, когда формула в условии действительно есть.
 */
readonly class LatexPageShortcodeService {

	private const SHORTCODE = '[latexpage]';

	/**
	 * Формула — пара `$…$` (в том числе `$$…$$`) с непустым содержимым внутри
	 * одной строки. Одиночный знак доллара (цена, переменная оболочки) под
	 * правило не подходит и шорткод не тянет.
	 */
	private const FORMULA_RE = '/\$\$?[^$\n]+\$\$?/';

	/**
	 * @param string $html Условие после санитайзинга
	 *
	 * @return string Условие с шорткодом в начале, если формула есть и шорткода ещё нет
	 */
	public function ensure( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( str_contains( $html, self::SHORTCODE ) ) {
			return $html;
		}

		if ( ! preg_match( self::FORMULA_RE, $html ) ) {
			return $html;
		}

		return self::SHORTCODE . "\n" . $html;
	}

	/**
	 * Формулы условия для MathJax (станции ЕГЭ и ОГЭ, плеер): `$…$` → `\(…\)`, `$$…$$` → `\[…\]`,
	 * сам `[latexpage]` убирается. Работает только у условия с шорткодом — это пометка автора, что
	 * доллары в тексте — формулы (иначе «5$ и 6$» превратились бы в формулу). Если QuickLaTeX активен,
	 * шорткод к этому моменту уже разобран (формулы стали картинками) и условие остаётся как есть.
	 *
	 * @param string $html Условие после `the_content`
	 */
	public function toMathJax( string $html ): string {
		$html = $this->katexToMathJax( $html );

		if ( ! str_contains( $html, self::SHORTCODE ) ) {
			return $html;
		}

		$html = trim( str_replace( self::SHORTCODE, '', $html ) );
		$html = (string) preg_replace( '/\$\$([^$\n]+)\$\$/', '\\\\[$1\\\\]', $html );

		return (string) preg_replace( '/\$([^$\n]+)\$/', '\\\\($1\\\\)', $html );
	}

	/**
	 * Шорткод плагина KaTeX `[katex]…[/katex]` (и `[latex]`) → делимитеры MathJax: `\(…\)`, а при
	 * `display="true"` — `\[…\]`. Условия задания не проходят `do_shortcode`, поэтому без этого
	 * формула выводилась бы текстом; MathJax уже подключён на станциях ЕГЭ/ОГЭ, в плеере и контрольной,
	 * и читает тот же TeX, что KaTeX.
	 *
	 * @param string $html Условие
	 */
	private function katexToMathJax( string $html ): string {
		if ( ! str_contains( $html, '[katex' ) && ! str_contains( $html, '[latex ' ) && ! str_contains( $html, '[latex]' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/\[(?:katex|latex)((?:\s+[^\]]*)?)\](.*?)\[\/(?:katex|latex)\]/s',
			static function ( array $m ): string {
				$tex = trim( (string) preg_replace( '|<br\s*/?>|', "\n", $m[2] ) );
				if ( '' === $tex ) {
					return $m[0];
				}

				$display = (bool) preg_match( '/display\s*=\s*["\']?(?:true|1)/i', $m[1] );

				return $display ? '\\[' . $tex . '\\]' : '\\(' . $tex . '\\)';
			},
			$html
		);
	}
}
