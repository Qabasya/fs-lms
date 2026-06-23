<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class HintField
 *
 * Rich-text поле подсказки. Присутствует у всех типов заданий.
 * Пустая подсказка скрывает кнопку «Подсказка» на фронте.
 *
 * @package Inc\MetaBoxes\Fields
 */
class HintField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$field_name = $this->get_field_name( $id );
		$editor_id  = strtolower( preg_replace( '/[^a-z0-9_]/i', '_', $id ) );
		?>
		<div class="fs-lms-field-group fs-lms-hint-field">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<p class="description">Оставьте пустым, чтобы скрыть кнопку «Подсказка» у ученика.</p>
			<div class="fs-lms-editor-wrapper">
				<?php
				wp_editor(
					(string) $value,
					$editor_id,
					array(
						'textarea_name' => $field_name,
						'textarea_rows' => 6,
						'media_buttons' => false,
						'tinymce'       => true,
						'quicktags'     => true,
						'wpautop'       => true,
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return $this->sanitizeHtmlValue( $value );
	}

	public function editorType(): string {
		return 'hint';
	}
}
