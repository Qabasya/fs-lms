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
}
