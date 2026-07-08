<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class EditorField
 *
 * WYSIWYG-поле на базе `wp_editor()` (TinyMCE). Хранит HTML в `fs_lms_meta[$id]`,
 * санитизируется через `wp_kses_post` (см. {@see sanitize()}). Используется для
 * длинных форматированных описаний — напр. интро-текст экзамена (T16.15, D16.4).
 *
 * @package Inc\MetaBoxes\Fields
 */
class EditorField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$content   = is_string( $value ) ? $value : '';
		$editor_id = 'fs_lms_editor_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( $id ) );
		?>
		<div class="fs-lms-field-group fs-lms-editor-field">
			<label class="fs-lms-label" for="<?php echo esc_attr( $editor_id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<?php
			wp_editor(
				$content,
				$editor_id,
				array(
					'textarea_name' => $this->get_field_name( $id ),
					'textarea_rows' => 8,
					'media_buttons' => false,
					'teeny'         => true,
					'quicktags'     => true,
				)
			);
			?>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		return $this->sanitizeHtmlValue( $value );
	}

	public function editorType(): string {
		return 'editor';
	}
}
