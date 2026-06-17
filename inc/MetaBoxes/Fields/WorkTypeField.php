<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Enums\WorkType;

/**
 * Class WorkTypeField
 *
 * Select типа работы (практика / СР / ДЗ).
 *
 * @package Inc\MetaBoxes\Fields
 */
class WorkTypeField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$current = WorkType::fromValueOrDefault( is_string( $value ) ? $value : '' )->value;
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					class="fs-lms-work-type-select">
				<?php foreach ( WorkType::options() as $val => $title ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return WorkType::fromValueOrDefault( is_string( $value ) ? $value : '' )->value;
	}
}
