<?php

namespace Inc\MetaBoxes\Fields;


/**
 * Класс для многострочного текстового поля (textarea).
 */
class TextareaField extends BaseField {
	public function render( $post, string $id, string $label, $value ): void {
		?>
        <div class="fs-lms-field-group">
            <label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
            </label>
            <div class="fs-lms-input-wrapper">
             <textarea id="<?php echo esc_attr( $id ); ?>"
                       name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
                       rows="8"
                       class="large-text fs-lms-textarea"><?php echo esc_textarea( $value ); ?></textarea>
            </div>
        </div>
		<?php
	}

	public function sanitize( $value ) {
		return wp_kses_post( $value );
	}
}