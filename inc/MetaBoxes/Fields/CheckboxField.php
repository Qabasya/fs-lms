<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class CheckboxField
 *
 * Флажок (input type="checkbox"). Перед ним идёт скрытое поле со значением `0`:
 * снятый флажок в POST не приходит вовсе, а `MetaBoxManager::saveFieldsMerge()`
 * сохраняет только присланные ключи — без скрытого поля выключить флажок было бы
 * нельзя. Хранится `1` / `0`.
 *
 * @package Inc\MetaBoxes\Fields
 */
class CheckboxField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		?>
		<div class="fs-field fs-field--checkbox">
			<input type="hidden" name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>" value="0">
			<label class="fs-field__label" for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox"
					   id="<?php echo esc_attr( $id ); ?>"
					   name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					   value="1"
					   <?php checked( self::isOn( $value ) ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return self::isOn( $value ) ? 1 : 0;
	}

	public function editorType(): string {
		return 'checkbox';
	}

	private static function isOn( mixed $value ): bool {
		return in_array( $value, array( 1, '1', true ), true );
	}
}
