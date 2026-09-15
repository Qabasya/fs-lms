<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Services;

/**
 * Class EditorFieldRenderer
 *
 * HTML поля `fs_lms_encoded_text` в форме элемента WPBakery.
 *
 * @package Inc\Modules\ArticleBlocks\Services
 */
final readonly class EditorFieldRenderer {

	public function __construct(
		private BlockValueCodec $codec,
	) {}

	/**
	 * Колбэк формы поля для `vc_add_shortcode_param()`.
	 *
	 * @param array<string, mixed> $settings Описание параметра из `vc_map()`
	 * @param mixed                $value    Сохранённое значение атрибута
	 *
	 * @return string
	 */
	public function render( array $settings, mixed $value ): string {
		$param_name = (string) ( $settings['param_name'] ?? '' );
		$param_type = (string) ( $settings['type'] ?? '' );
		$mode       = 'table' === ( $settings['fs_mode'] ?? '' ) ? 'table' : 'code';
		$stored     = is_string( $value ) ? $value : '';
		$text       = $this->codec->decodeText( $stored );

		ob_start();
		require dirname( __DIR__ ) . '/templates/encoded-field.php';

		return (string) ob_get_clean();
	}
}
