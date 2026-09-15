<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

/**
 * Class BlockValueCodec
 *
 * Хранение значений блоков в атрибутах шорткодов.
 *
 * @package Inc\Modules\ArticleBlocks\Services
 *
 * ### Код и таблица — base64
 *
 * Многострочный текст с `[`, `]` и кавычками в атрибуте шорткода как есть не выживает:
 * `]` закрывает тег, а переводы строк при выводе попадают под `wpautop`. Встроенный тип
 * WPBakery `textarea_safe` кодирует значение только при `"` или `http` внутри, остальное пишет
 * открытым текстом. Поэтому поле модуля (`assets/editor-fields.js`) кодирует ВСЕГДА:
 * `fsb64:` + base64 от UTF-8. Для совместимости читается и формат `textarea_safe` (`#E-8_`).
 *
 * ### Текстовые поля WPBakery
 *
 * Обычные атрибуты редактор экранирует сам: `[` → `` `{` ``, `]` → `` `}` ``, `"` → ``` `` ```.
 */
final readonly class BlockValueCodec {

	/** Префикс значения поля модуля. */
	public const PREFIX = 'fsb64:';

	/** Префикс значения `textarea_safe` WPBakery. */
	private const VC_SAFE_PREFIX = '#E-8_';

	/**
	 * Текст многострочного поля (код, строки таблицы).
	 *
	 * @param string $stored Значение атрибута
	 *
	 * @return string Текст с переводами строк `\n`; '' — пусто или значение повреждено
	 */
	public function decodeText( string $stored ): string {
		if ( str_starts_with( $stored, self::PREFIX ) ) {
			$text = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		} elseif ( str_starts_with( $stored, self::VC_SAFE_PREFIX ) ) {
			$decoded = base64_decode( substr( $stored, strlen( self::VC_SAFE_PREFIX ) ), true );
			$text    = false === $decoded ? false : rawurldecode( $decoded );
		} else {
			$text = $this->decodeAttr( $stored );
		}

		if ( false === $text || ! mb_check_encoding( $text, 'UTF-8' ) ) {
			return '';
		}

		return str_replace( array( "\r\n", "\r" ), "\n", $text );
	}

	/**
	 * Кодирует текст так же, как поле модуля в редакторе.
	 *
	 * @param string $text Текст
	 *
	 * @return string
	 */
	public function encodeText( string $text ): string {
		return '' === $text ? '' : self::PREFIX . base64_encode( $text );
	}

	/**
	 * Значение обычного текстового поля WPBakery (подпись, заголовок).
	 *
	 * @param string $value Значение атрибута
	 *
	 * @return string
	 */
	public function decodeAttr( string $value ): string {
		return str_replace( array( '`{`', '`}`', '``' ), array( '[', ']', '"' ), $value );
	}
}
