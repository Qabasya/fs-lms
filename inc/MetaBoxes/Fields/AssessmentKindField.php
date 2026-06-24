<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Enums\Assessment\AssessmentKind;

/**
 * Class AssessmentKindField
 *
 * Select типа экзамена (контрольная / ЕГЭ / компьютерный ЕГЭ).
 *
 * @package Inc\MetaBoxes\Fields
 */
class AssessmentKindField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$current = AssessmentKind::fromValueOrDefault( is_string( $value ) ? $value : '' )->value;
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					class="fs-lms-assessment-kind-select">
				<?php foreach ( AssessmentKind::options() as $option ) : ?>
					<option value="<?php echo esc_attr( $option['value'] ); ?>"
						<?php selected( $current, $option['value'] ); ?>>
						<?php echo esc_html( $option['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return AssessmentKind::fromValueOrDefault( is_string( $value ) ? $value : '' )->value;
	}
}
