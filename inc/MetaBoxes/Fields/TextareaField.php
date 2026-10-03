<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use WP_Post;

/**
 * Class TextareaField
 *
 * Многострочное текстовое поле (textarea) без HTML-редактора.
 *
 * Отличие от {@see InputField}: переводы строк сохраняются как есть — нужно
 * там, где сама разбивка на строки несёт смысл (эталонный ответ из нескольких
 * строк). Отличие от {@see ConditionField}: это чистый текст, а не HTML.
 *
 * @package Inc\MetaBoxes\Fields
 * @extends BaseField
 */
class TextareaField extends BaseField {

	/**
	 * Рендерит HTML-разметку многострочного поля ввода.
	 *
	 * @param WP_Post $post  Текущий пост (не используется, но обязателен для интерфейса)
	 * @param string  $id    Уникальный идентификатор поля
	 * @param string  $label Текст метки (label) поля
	 * @param mixed   $value Текущее значение поля
	 *
	 * @return void
	 */
	public function render( \WP_Post $post, string $id, string $label, mixed $value ): void {
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div class="fs-lms-input-wrapper">
				<textarea id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
						rows="5"
						class="large-text fs-lms-input fs-lms-textarea"><?php echo esc_textarea( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Санитизация значения поля.
	 *
	 * Эталон ответа — обычный текст, в котором `<` и `>` могут быть всем ответом.
	 * Значение уже прошло wp_unslash() в вызывающем коллбеке, повторно снимать
	 * слэши нельзя (пути и регулярные выражения тоже бывают ответом).
	 *
	 * @param mixed $value Сырое значение из POST-запроса
	 *
	 * @return string Очищенный многострочный текст
	 */
	public function sanitize( mixed $value ): mixed {
		return $this->sanitizeAnswerTextValue( $value );
	}

	public function editorType(): string {
		return 'multiline_text';
	}
}
