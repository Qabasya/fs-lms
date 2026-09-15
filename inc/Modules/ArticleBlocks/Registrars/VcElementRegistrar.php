<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Registrars;

/**
 * Class VcElementRegistrar
 *
 * Обёртка над API регистрации WPBakery (`vc_map()`, `vc_add_shortcode_param()`).
 *
 * Функции существуют только при активном WPBakery: вызывающий код регистрирует элементы
 * на `vc_before_init`, а проверка здесь страхует от вызова в другом контексте.
 *
 * @package Inc\Modules\ArticleBlocks\Registrars
 */
final readonly class VcElementRegistrar {

	/**
	 * Доступно ли API WPBakery.
	 */
	public function isAvailable(): bool {
		return function_exists( 'vc_map' ) && function_exists( 'vc_add_shortcode_param' );
	}

	/**
	 * Регистрирует собственный тип поля.
	 *
	 * @param string   $type      Имя типа
	 * @param callable $formField Колбэк HTML поля ($settings, $value): string
	 * @param string   $scriptUrl JS поля (WPBakery подключает его в форме элемента)
	 *
	 * @return void
	 */
	public function addParamType( string $type, callable $formField, string $scriptUrl ): void {
		if ( $this->isAvailable() ) {
			vc_add_shortcode_param( $type, $formField, $scriptUrl );
		}
	}

	/**
	 * Регистрирует элемент.
	 *
	 * @param array<string, mixed> $element Описание элемента
	 *
	 * @return void
	 */
	public function mapElement( array $element ): void {
		if ( $this->isAvailable() ) {
			vc_map( $element );
		}
	}
}
