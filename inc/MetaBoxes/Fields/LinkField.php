<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Class LinkField
 *
 * Поле для ввода ссылки на файл (задания, дополнительные материалы).
 * Расширяет InputField, добавляя специфическую валидацию URL
 * и кнопку проверки/перехода по ссылке.
 *
 * @package Inc\MetaBoxes\Fields
 * @extends InputField
 */
class LinkField extends InputField {
	/**
	 * Рендерит HTML-разметку поля для ввода URL.
	 *
	 * Выводит input с типом url, кнопку проверки ссылки
	 * (если значение уже заполнено) и подсказку для пользователя.
	 *
	 * @param WP_Post $post  Текущий пост (не используется, но обязателен для интерфейса)
	 * @param string  $id    Уникальный идентификатор поля
	 * @param string  $label Текст метки (label) поля
	 * @param string  $value Текущее значение поля (URL)
	 *
	 * @return void
	 */
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

				<?php if ( $value ) : ?>
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

	/**
	 * Санитизация значения поля.
	 *
	 * Использует встроенную WordPress-функцию esc_url_raw(),
	 * которая очищает URL: удаляет опасные символы, проверяет протокол,
	 * удаляет лишние пробелы и экранирует специальные символы.
	 *
	 * @param mixed $value Сырое значение из POST-запроса
	 *
	 * @return string Очищенный URL
	 */
	public function sanitize( $value ): string {
		return esc_url_raw( $value );
	}
}
