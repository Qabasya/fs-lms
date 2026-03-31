<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Class InputField
 *
 * Поле для обычного текстового ввода (input type="text").
 * Использует стандартную WordPress-санитизацию для текстовых полей.
 *
 * @package Inc\MetaBoxes\Fields
 * @extends BaseField
 */
class InputField extends BaseField
{
    /**
     * Рендерит HTML-разметку текстового поля ввода.
     *
     * Выводит стандартный input с типом text, используя стили WordPress.
     *
     * @param WP_Post $post  Текущий пост (не используется, но обязателен для интерфейса)
     * @param string  $id    Уникальный идентификатор поля
     * @param string  $label Текст метки (label) поля
     * @param string  $value Текущее значение поля
     *
     * @return void
     */
    public function render($post, string $id, string $label, $value): void
    {
        ?>
        <div class="fs-lms-field-group">
            <label class="fs-lms-label" for="<?php echo esc_attr($id); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <div class="fs-lms-input-wrapper">
                <input type="text"
                       id="<?php echo esc_attr($id); ?>"
                       name="<?php echo esc_attr($this->get_field_name($id)); ?>"
                       value="<?php echo esc_attr($value); ?>"
                       class="large-text fs-lms-input">
            </div>
        </div>
        <?php
    }

    /**
     * Санитизация значения поля.
     *
     * Использует встроенную WordPress-функцию sanitize_text_field(),
     * которая удаляет HTML-теги и экранирует специальные символы.
     *
     * @param mixed $value Сырое значение из POST-запроса
     *
     * @return string Очищенное текстовое значение
     */
    public function sanitize($value)
    {
        return sanitize_text_field($value);
    }
}