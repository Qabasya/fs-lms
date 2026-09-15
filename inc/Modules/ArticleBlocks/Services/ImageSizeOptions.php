<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

/**
 * Class ImageSizeOptions
 *
 * Размеры картинки для блока «Изображение»: список для редактора и проверка значения при выводе.
 *
 * Новых размеров модуль не регистрирует — это породило бы лишние превью у каждой загрузки
 * на сайте. Берутся зарегистрированные темой и ядром плюс оригинал; точную ширину задаёт поле
 * «Ширина, px».
 *
 * @package Inc\Modules\ArticleBlocks\Services
 */
final readonly class ImageSizeOptions {

	/** Размер по умолчанию. */
	public const DEFAULT = 'large';

	/** Оригинал файла. */
	private const FULL = 'full';

	/**
	 * Варианты для выпадающего списка WPBakery: подпись → слаг размера.
	 *
	 * @return array<string, string>
	 */
	public function options(): array {
		$options = array();

		foreach ( wp_get_registered_image_subsizes() as $slug => $size ) {
			$width  = (int) ( $size['width'] ?? 0 );
			$height = (int) ( $size['height'] ?? 0 );
			$box    = $width > 0 && $height > 0 ? "{$width}×{$height}" : ( $width > 0 ? "ширина {$width}" : "высота {$height}" );

			$options[ "{$slug} ({$box})" ] = (string) $slug;
		}

		$options['Оригинал'] = self::FULL;

		return $options;
	}

	/**
	 * Размер из атрибута, если он зарегистрирован; иначе размер по умолчанию.
	 *
	 * @param string $size Значение атрибута
	 *
	 * @return string
	 */
	public function resolve( string $size ): string {
		if ( self::FULL === $size || array_key_exists( $size, wp_get_registered_image_subsizes() ) ) {
			return $size;
		}

		return self::DEFAULT;
	}
}
