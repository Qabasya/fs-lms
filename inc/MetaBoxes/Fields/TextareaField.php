<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Class TextareaField
 *
 * Поле для многострочного текстового ввода (textarea).
 * Сохраняет HTML-контент с использованием wp_kses_post для безопасности.
 *
 * @package Inc\MetaBoxes\Fields
 * @extends BaseField
 */
class TextareaField extends BaseField {
    /**
     * Рендерит HTML-разметку текстовой области.
     *
     * Выводит textarea с возможностью ввода многострочного текста,
     * включая базовое HTML-форматирование.
     *
     * @param WP_Post $post Текущий пост (не используется, но обязателен для интерфейса)
     * @param string $id Уникальный идентификатор поля
     * @param string $label Текст метки (label) поля
     * @param string $value Текущее значение поля
     *
     * @return void
     */
    public function render( $post, string $id, string $label, $value ): void {
        ?>
        <div class="fs-lms-field-group">
            <label class="fs-lms-label" for="<?php echo esc_attr( $id ); ?>">
                <?php echo esc_html( $label ); ?>
            </label>
            <div class="fs-lms-input-wrapper">
                <textarea id="<?php echo esc_attr( $id ); ?>"
                          name="<?php echo esc_attr( $this->get_field_name( $id ) ); ?>"
                          rows="8"
                          class="large-text fs-lms-textarea"><?php echo esc_textarea( $value ); ?></textarea>
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