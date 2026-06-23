<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class AudioField
 *
 * Поле выбора аудио-вложения через медиабиблиотеку WordPress.
 *
 * Хранит: { attachment_id: int }
 * POST-структура: fs_lms_meta[task_audio][attachment_id]
 *
 * @package Inc\MetaBoxes\Fields
 */
class AudioField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data          = is_array( $value ) ? $value : array();
		$attachment_id = (int) ( $data['attachment_id'] ?? 0 );
		$base          = esc_attr( $this->get_field_name( $id ) );

		$url   = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		$title = $attachment_id ? get_the_title( $attachment_id ) : '';
		?>
		<div class="fs-lms-field-group fs-task-audio-field" data-field="task-audio">
			<label class="fs-lms-label"><?php echo esc_html( $label ); ?></label>

			<input type="hidden"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo $base; ?>[attachment_id]"
				value="<?php echo $attachment_id; ?>"
				class="js-audio-attachment-id">

			<div class="fs-task-audio__preview" <?php echo $url ? '' : 'style="display:none"'; ?>>
				<audio controls src="<?php echo esc_url( (string) $url ); ?>" class="js-audio-player"></audio>
				<span class="js-audio-title"><?php echo esc_html( (string) $title ); ?></span>
			</div>

			<div class="fs-task-audio__actions">
				<button type="button" class="button js-audio-select">
					<?php echo $attachment_id ? 'Заменить аудио' : 'Выбрать аудио'; ?>
				</button>
				<?php if ( $attachment_id ) : ?>
					<button type="button" class="button-link js-audio-remove">Удалить</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'attachment_id' => 0 );
		}

		return array(
			'attachment_id' => max( 0, (int) ( $value['attachment_id'] ?? 0 ) ),
		);
	}

	public function editorType(): string {
		return 'audio';
	}
}
