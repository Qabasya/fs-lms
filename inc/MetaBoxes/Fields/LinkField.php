<?php

namespace Inc\MetaBoxes\Fields;

use Inc\MetaBoxes\Fields\InputField;

/**
 * Класс для ссылки на файл (задания, доп. материалы).
 */
class LinkField extends InputField {
	public function render( $post, string $id, string $label, $value ): void {
		?>
        <div class="fs-lms-field-group fs-lms-file-group">
            <label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
            </label>
            <div class="fs-lms-input-wrapper">
                <input type="url"
                       id="<?php echo esc_attr( $id ); ?>"
                       name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
                       value="<?php echo esc_url( $value ); ?>"
                       placeholder="https://..."
                       class="large-text fs-lms-input fs-lms-file-input">

				<?php if ( $value ): ?>
                    <a href="<?php echo esc_url( $value ); ?>"
                       target="_blank"
                       class="button button-secondary"
                       title="Проверить ссылку">
                        <span class="dashicons dashicons-external" style="margin-top: 4px;"></span>
                    </a>
				<?php endif; ?>
            </div>
            <p class="description">Вставьте прямую ссылку на файл</p>
        </div>
		<?php
	}

	public function sanitize( $value ) {
		return esc_url_raw( $value );
	}
}