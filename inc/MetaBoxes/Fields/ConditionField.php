<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Class ConditionField
 *
 * Поле для многострочного текстового ввода (textarea).
 *
 * ТОЛЬКО для условий!!!
 *
 * Сохраняет HTML-контент с использованием wp_kses_post для безопасности.
 *
 * @package Inc\MetaBoxes\Fields
 * @extends BaseField
 */
class ConditionField extends BaseField {
	/**
	 * Рендерит HTML-разметку текстовой области.
	 *
	 * Выводит textarea с возможностью ввода многострочного текста,
	 * включая базовое HTML-форматирование.
	 *
	 * @param WP_Post $post  Текущий пост (не используется, но обязателен для интерфейса)
	 * @param string  $id    Уникальный идентификатор поля
	 * @param string  $label Текст метки (label) поля
	 * @param string  $value Текущее значение поля
	 *
	 * @return void
	 */
	public function render( $post, string $id, string $label, $value ): void {
		?>
		<div class="fs-lms-field-group">
			<label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div class="fs-lms-editor-wrapper">
				<?php
				// 1. Формируем чистое имя поля для обработки в MetaBoxController
				$field_name = $this->get_field_name( $id );

				// 2. Генерируем ID для редактора (должен быть только строчными буквами и подчеркиваниями)
				$editor_id = strtolower( preg_replace( '/[^a-z0-9_]/i', '_', $id ) );

				// 3. Настройки редактора для соответствия скриншотам
				$settings = array(
					'textarea_name' => $field_name,   // Имя для массива fs_lms_meta
					'textarea_rows' => 12,            // Высота поля
					'media_buttons' => true,          // Кнопка "Добавить медиафайл"
					'tinymce'       => true,          // Вкладка "Визуально"
					'quicktags'     => true,          // Вкладка "Текст" (Код)
					'wpautop'       => true,          // Автоматические параграфы <p>
				);

				// 4. Отрисовка
				wp_editor( (string) $value, $editor_id, $settings );
				?>
			</div>
		</div>
		<?php
	}


	/**
	 * Санитизация значения поля.
	 *
	 * Использует встроенную WordPress-функцию wp_kses_post(),
	 * которая разрешает только безопасные HTML-теги, атрибуты и стили,
	 * допустимые в записях WordPress.
	 *
	 * @param mixed $value Сырое значение из POST-запроса
	 *
	 * @return string Очищенный HTML-контент
	 */
	public function sanitize( $value ) {
		return wp_kses_post( $value );
	}
}
