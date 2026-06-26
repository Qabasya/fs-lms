<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class NumberInputField
 *
 * Числовое поле ввода (input type="number").
 *
 * @package Inc\MetaBoxes\Fields
 */
class NumberInputField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		?>
		<div class="fs-field">
			<label class="fs-field__label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div class="fs-field__control">
				<input type="number"
					   id="<?php echo esc_attr( $id ); ?>"
					   name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					   value="<?php echo esc_attr( (string) ( (int) $value ) ); ?>"
					   min="0">
			</div>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return $this->sanitizeIntValue( $value );
	}

	public function editorType(): string {
		return 'number';
	}
}
