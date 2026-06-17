<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class TextareaField
 *
 * Простое многострочное текстовое поле (инструкции, комментарии).
 *
 * @package Inc\MetaBoxes\Fields
 */
class TextareaField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$content = is_string( $value ) ? $value : '';
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<textarea id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					class="large-text"
					rows="3"><?php echo esc_textarea( $content ); ?></textarea>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return $this->sanitizeEditorContent( is_string( $value ) ? $value : '' );
	}
}
