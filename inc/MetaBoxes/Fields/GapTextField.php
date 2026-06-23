<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

/**
 * Class GapTextField
 *
 * Поле текста с пропусками для задания «Пропуски в тексте».
 * Синтаксис пропуска: [[ответ]] или [[вар1|вар2]] для синонимов.
 *
 * Хранит: { text: 'Текст с [[пропусками]] внутри' }
 * POST-структура: fs_lms_meta[task_gap_text][text]
 *
 * @package Inc\MetaBoxes\Fields
 */
class GapTextField extends BaseField {

	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		$data = is_array( $value ) ? $value : array();
		$text = (string) ( $data['text'] ?? '' );
		$base = esc_attr( $this->get_field_name( $id ) );
		?>
		<div class="fs-lms-field-group fs-task-gap-field">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<p class="description">
				Заключайте пропускаемые слова в <code>[[двойные скобки]]</code>.
				Синонимы: <code>[[красный|алый|пурпурный]]</code> — подходит любое.
				Проверка без учёта регистра.
			</p>
			<textarea
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo $base; ?>[text]"
				rows="8"
				class="large-text code"
			><?php echo esc_textarea( $text ); ?></textarea>
		</div>
		<?php
	}

	public function sanitize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return array( 'text' => '' );
		}

		return array(
			'text' => wp_kses_post( wp_unslash( (string) ( $value['text'] ?? '' ) ) ),
		);
	}

	public function editorType(): string {
		return 'gap_text';
	}
}
