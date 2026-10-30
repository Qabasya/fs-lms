<?php

declare( strict_types=1 );

namespace Inc\Shared;

/**
 * Class TileText
 *
 * Текст плитки и формулы задания «Перетаскивание пропусков». Из разметки допустимы только индексы и степени:
 * `<sub>` и `<sup>` без атрибутов (x<sub>1</sub>, 2<sup>n</sup>); остальное вырезается при сохранении и экранируется при выводе.
 * Теги — часть значения плитки: `x<sub>1</sub>` и `x1` разные плитки; ответ ученика сравнивается со значением вместе с тегами.
 * JS-двойник вывода — `src/js/common/tile-html.js`.
 *
 * @package Inc\Shared
 */
final class TileText {

	/** Допустимый тег целиком: `<sub>`, `</sup>`, с любыми атрибутами и в любом регистре. */
	private const ALLOWED = '/<(\/?)(sub|sup)\b[^>]*>/i';

	/** Любая разметка: тег с именем (`<b>`, `</p>`, `<img …>`); `a < b` и `a<b` тегом не считаются. */
	private const ANY_TAG = '/<\/?[a-z][^>]*>/i';

	/**
	 * Значение для хранения: только `<sub>`/`<sup>` без атрибутов, закрытые и вложенные правильно, остальные теги вырезаны.
	 */
	public static function clean( string $raw ): string {
		// Текст между допустимыми тегами очищается от прочей разметки; теги собираются заново, с проверкой вложенности.
		$out   = '';
		$stack = array();
		preg_match_all( self::ALLOWED, $raw, $tags, PREG_SET_ORDER );
		$texts = preg_split( self::ALLOWED, $raw ) ?: array( $raw );

		foreach ( $texts as $i => $text ) {
			$out .= (string) preg_replace( self::ANY_TAG, '', $text );
			$tag  = $tags[ $i ] ?? null;
			if ( null === $tag ) {
				continue;
			}
			$name = strtolower( $tag[2] );
			if ( '' === $tag[1] ) {
				$stack[] = $name;
				$out    .= "<{$name}>";
			} elseif ( array() !== $stack && end( $stack ) === $name ) {
				array_pop( $stack );
				$out .= "</{$name}>";
			}
		}

		while ( array() !== $stack ) {
			$out .= '</' . array_pop( $stack ) . '>';
		}

		return trim( $out );
	}

	/**
	 * Безопасный HTML для вывода: всё экранируется, кроме `<sub>`/`<sup>` без атрибутов.
	 */
	public static function html( string $stored ): string {
		$escaped = htmlspecialchars( $stored, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		return (string) preg_replace( '/&lt;(\/?)(sub|sup)&gt;/', '<$1$2>', $escaped );
	}

	/** Читаемый текст без разметки (эталон в строке, журнал): `x<sub>1</sub>` → `x1`. */
	public static function plain( string $stored ): string {
		return (string) preg_replace( self::ALLOWED, '', $stored );
	}
}
