<?php

namespace Inc\MetaBoxes\Fields;

/**
 * Class CodeField
 *
 * Поле для ввода кода (textarea с моноширинным шрифтом).
 * Сохраняет форматированный код с сохранением отступов и пробелов.
 *
 * @package Inc\MetaBoxes\Fields
 * @extends BaseField
 */
class CodeField extends BaseField {
    /**
     * Рендерит HTML-разметку поля для ввода кода.
     *
     * Выводит textarea с моноширинным оформлением и описанием.
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

    /**
     * Санитизация значения поля.
     *
     * Код не требует дополнительной обработки, так как сохраняется как есть
     * с сохранением всех отступов и пробелов. Безопасность вывода обеспечивается
     * esc_textarea() при рендеринге и esc_html() при отображении в шаблоне.
     *
     * @param mixed $value Сырое значение из POST-запроса
     *
     * @return string Оригинальное значение без изменений
     */
    public function sanitize( $value ) {
        // Ничего не трогаем, сохраняем текст со всеми отступами и пробелами
        return $value;
    }
}