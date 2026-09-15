<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

/**
 * Class ShortcodeMediaRefs
 *
 * Ссылки на вложения в атрибутах шорткодов: извлечение ID для пакета и их замена при импорте.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Зачем
 *
 * Статьи пишутся в WPBakery: картинка там лежит в контенте не тегом `<img class="wp-image-143">`,
 * а шорткодом `[vc_single_image image="143"]`. Ни класса, ни URL в тексте нет, поэтому
 * {@see MediaCollector} такое вложение не находил, а {@see MediaUrlRewriter} не переписывал ID.
 *
 * ### Карта шорткодов
 *
 * По умолчанию — элементы самого WPBakery (формат контента, а не наш модуль). Модули добавляют
 * свои шорткоды фильтром {@see self::FILTER}: ядро о модулях не знает. Значение атрибута —
 * один ID или список через запятую (`images="1,2,3"`).
 */
final class ShortcodeMediaRefs {

	/**
	 * Фильтр карты: `array<string, string[]>` — тег шорткода → атрибуты с ID вложений.
	 */
	public const string FILTER = 'fs_lms_bundle_shortcode_media_attrs';

	/**
	 * Элементы WPBakery, хранящие ID вложений в атрибутах.
	 */
	private const array DEFAULTS = array(
		'vc_single_image'    => array( 'image' ),
		'vc_gallery'         => array( 'images' ),
		'vc_images_carousel' => array( 'images' ),
	);

	/**
	 * ID вложений из атрибутов известных шорткодов.
	 *
	 * @param string $text Контент записи или строка меты
	 *
	 * @return int[] ID в порядке появления, без повторов
	 */
	public function extractIds( string $text ): array {
		$ids = array();

		$this->walk(
			$text,
			static function ( string $value ) use ( &$ids ): string {
				foreach ( explode( ',', $value ) as $candidate ) {
					$id = (int) trim( $candidate );
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}

				return $value;
			}
		);

		return $ids;
	}

	/**
	 * Заменяет ID вложений в атрибутах известных шорткодов.
	 *
	 * Каждое число заменяется ровно один раз: цепочка 143→201 и 201→305 не превратит 143 в 305.
	 * ID, которого нет в карте (файл не попал в пакет), остаётся как есть — как и `wp-image-{id}`.
	 *
	 * @param string          $text  Текст
	 * @param array<int, int> $idMap Старый ID → новый
	 *
	 * @return string
	 */
	public function rewrite( string $text, array $idMap ): string {
		if ( array() === $idMap ) {
			return $text;
		}

		return $this->walk(
			$text,
			static function ( string $value ) use ( $idMap ): string {
				$parts = array_map(
					static function ( string $candidate ) use ( $idMap ): string {
						$id = (int) trim( $candidate );

						return ( $id > 0 && isset( $idMap[ $id ] ) ) ? (string) $idMap[ $id ] : trim( $candidate );
					},
					explode( ',', $value )
				);

				return implode( ',', $parts );
			}
		);
	}

	/**
	 * Проходит по значениям атрибутов-вложений и подставляет результат колбэка.
	 *
	 * Атрибуты WPBakery всегда в двойных кавычках, а `]` внутри значений редактор кодирует
	 * как `` `}` `` — поэтому тело открывающего тега читается до первой `]`.
	 *
	 * @param string                    $text    Текст
	 * @param callable(string): string  $mapper  Значение атрибута → новое значение
	 *
	 * @return string
	 */
	private function walk( string $text, callable $mapper ): string {
		if ( ! str_contains( $text, '[' ) ) {
			return $text;
		}

		foreach ( $this->map() as $tag => $attributes ) {
			if ( ! str_contains( $text, '[' . $tag ) ) {
				continue;
			}

			$tagPattern  = '/\[' . preg_quote( $tag, '/' ) . '(?=[\s\]\/])[^\]]*\]/';
			// Пробел перед именем обязателен: `data-image="…"` или `custom_image="…"` — чужие атрибуты.
			$attrPattern = '/(\s(?:' . implode( '|', array_map( static fn( string $a ): string => preg_quote( $a, '/' ), $attributes ) ) . ')\s*=\s*")([^"]*)(")/';

			$text = (string) preg_replace_callback(
				$tagPattern,
				static fn( array $tagMatch ): string => (string) preg_replace_callback(
					$attrPattern,
					static fn( array $attr ): string => $attr[1] . $mapper( $attr[2] ) . $attr[3],
					$tagMatch[0]
				),
				$text
			);
		}

		return $text;
	}

	/**
	 * Карта шорткодов с учётом модулей.
	 *
	 * @return array<string, string[]>
	 */
	private function map(): array {
		$map    = apply_filters( self::FILTER, self::DEFAULTS );
		$result = array();

		foreach ( is_array( $map ) ? $map : array() as $tag => $attributes ) {
			$tag        = (string) $tag;
			$attributes = array_values( array_filter( array_map( 'strval', (array) $attributes ) ) );

			if ( 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $tag ) && array() !== $attributes ) {
				$result[ $tag ] = $attributes;
			}
		}

		return $result;
	}
}
