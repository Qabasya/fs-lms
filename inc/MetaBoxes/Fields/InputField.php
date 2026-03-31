<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Класс для обычного текстового поля (input type="text").
 */
class InputField extends BaseField{
	/**
	 * Отрисовка HTML.
	 * Здесь мы используем стандартную разметку WordPress для админки.
	 */
	public function render( $post, string $id, string $label, $value ): void {
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div class="fs-lms-input-wrapper">
				<input type="text"
				       id="<?php echo esc_attr( $id ); ?>"
				       name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
				       value="<?php echo esc_attr( $value ); ?>"
				       class="large-text fs-lms-input">
			</div>
		</div>
		<?php
	}

	/**
	 * Очистка данных.
	 * Для обычного текста идеально подходит встроенная функция WordPress.
	 */
	public function sanitize( $value ) {
		return sanitize_text_field( $value );
	}

}