<?php

namespace Inc\MetaBoxes\Fields;


/**
 * Класс для текстовой области с поддержкой WP Editor.
 */
class TextareaField extends BaseField{

	public function render( $post, string $id, string $label, $value ): void {
		echo '<p><strong>' . esc_html( $label ) . '</strong></p>';

		// Настраиваем редактор так, чтобы он не был слишком громоздким
		$settings = [
			'textarea_name' => $this->get_field_name( $id ),
			'media_buttons' => true, // Разрешаем загрузку файлов (изображений!) в текст условия
			'textarea_rows' => 10,
			'teeny'         => false,
			'quicktags'     => true
		];

		// Используем встроенную функцию WP для рендеринга TinyMCE
		wp_editor( $value, $id, $settings );
	}

	public function sanitize( $value ) {
		// Здесь используем wp_kses_post, чтобы сохранить разрешенные HTML-теги
		return wp_kses_post( $value );
	}
}