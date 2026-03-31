<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Класс для ввода кода (textarea с моноширинным шрифтом).
 */

class CodeField extends BaseField{

	public function render( $post, string $id, string $label, $value ): void {
		?>
		<div class="fs-lms-field-group fs-lms-code-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div class="fs-lms-input-wrapper">
             <textarea id="<?php echo esc_attr( $id ); ?>"
                       name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
                       rows="12"
                       spellcheck="false"
                       class="large-text fs-lms-textarea fs-lms-code-editor"><?php echo esc_textarea( $value ); ?></textarea>
				<p class="description">Введите код решения</p>
			</div>
		</div>
		<?php
	}

	public function sanitize( $value ) {
		// Ничего не трогаем, а передаём текст со всеми отступами
		return $value;
	}
}