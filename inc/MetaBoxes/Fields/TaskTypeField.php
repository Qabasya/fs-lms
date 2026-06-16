<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class TaskTypeField
 *
 * Опциональный select типа задания (терм таксономии {key}_task_number).
 * Управляет фильтром кандидатов в бакете.
 *
 * @package Inc\MetaBoxes\Fields
 */
class TaskTypeField extends BaseField {

	/** @var array<int, string> term_id => name */
	private array $options = array();

	/**
	 * @param array<int, string> $options
	 */
	public function setOptions( array $options ): void {
		$this->options = $options;
	}

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$current = (int) $value;
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
					class="fs-lms-task-type-select">
				<option value="0"><?php esc_html_e( '— Все типы —', 'fs-lms' ); ?></option>
				<?php foreach ( $this->options as $term_id => $name ) : ?>
					<option value="<?php echo esc_attr( (string) $term_id ); ?>"
						<?php selected( $current, $term_id ); ?>>
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return (int) $value;
	}
}
