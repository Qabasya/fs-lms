<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

/**
 * Class NbspTypographer
 *
 * Неразрывные пробелы против «висячих» слов: предлог, союз или частица не
 * остаются в конце строки, а переносятся вместе со следующим словом, тире не
 * начинает строку, «№» не отрывается от номера. Тексты в редакторе не правятся —
 * обработка на выводе (TypographyController — вся страница, AllTasksDataBuilder —
 * карточки, которые тренажёр догружает по AJAX).
 *
 * HTML разбирается на теги и текст между ними; меняется только текст. Код и
 * служебные элементы (`script`, `style`, `pre`, `code`, `textarea`) не трогаются,
 * атрибуты тегов — тоже. Повторная обработка ничего не меняет: после замены
 * за словом стоит уже неразрывный пробел, а правила ищут обычный.
 *
 * При любой ошибке разбора возвращается исходный HTML — страница важнее
 * типографики (урок cookie-согласия темы 1.6.5: сбой регулярки стирал подвал).
 *
 * @package Inc\Services\Shared
 */
class NbspTypographer {

	private const NBSP = "\u{00A0}";

	/** Элементы, внутри которых текст не трогаем. */
	private const RAW_TAGS = array( 'script', 'style', 'pre', 'code', 'textarea' );

	/**
	 * Короткое слово, после которого пробел неразрывный: любые 1–2 буквы
	 * (в, к, с, и, а, на, по, не, ни, до, из, от, за, но, же…) и короткие
	 * предлоги/союзы из трёх букв. Слово — целиком: перед ним не буква, не
	 * цифра и не дефис («из-за» не режем посередине).
	 */
	private const SHORT_WORD = '/(?<![\p{L}\p{N}_\-])(\p{L}{1,2}|без|для|над|под|при|про|или|что|как|изо|обо|ото)[ \t\r\n]+(?=[\p{L}\p{N}«"„(&<№]|$)/iu';

	/** Тире не начинает строку: пробел перед ним — неразрывный. */
	private const DASH = '/[ \t\r\n]+([—–])(?=[ \t\r\n]|$)/u';

	/** «№ 5» — знак не отрывается от номера. */
	private const NUMERO = '/№[ \t\r\n]+(?=\d)/u';

	/**
	 * Обрабатывает HTML: только текст между тегами, вне кода.
	 *
	 * @param string $html Готовая разметка (страница или её фрагмент).
	 */
	public function html( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		$out = '';
		$raw = '';

		foreach ( $parts as $i => $part ) {
			// Нечётные элементы — теги и комментарии (разделители preg_split).
			if ( 1 === $i % 2 ) {
				$raw  = $this->rawState( $part, $raw );
				$out .= $part;
				continue;
			}

			$out .= '' === $raw ? $this->text( $part ) : $part;
		}

		return $out;
	}

	/**
	 * Обрабатывает простой текст (без разметки).
	 *
	 * @param string $text Текст.
	 */
	public function text( string $text ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		$result = preg_replace(
			array( self::SHORT_WORD, self::DASH, self::NUMERO ),
			array( '$1' . self::NBSP, self::NBSP . '$1', '№' . self::NBSP ),
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Внутри какого «сырого» элемента мы после этого тега.
	 *
	 * @param string $tag Тег целиком (`<pre class="…">`, `</code>`).
	 * @param string $raw Текущий сырой элемент ('' — обычный текст).
	 */
	private function rawState( string $tag, string $raw ): string {
		if ( ! preg_match( '#^<(/?)([a-z][a-z0-9-]*)#i', $tag, $m ) ) {
			return $raw;
		}

		$name    = strtolower( $m[2] );
		$closing = '/' === $m[1];

		if ( '' === $raw ) {
			return ! $closing && in_array( $name, self::RAW_TAGS, true ) && ! str_ends_with( rtrim( $tag, '> ' ), '/' ) ? $name : '';
		}

		// Вложенный `code` в `pre` ничего не меняет: ждём закрытия того же тега.
		return $closing && $name === $raw ? '' : $raw;
	}
}
