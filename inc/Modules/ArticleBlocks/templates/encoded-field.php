<?php
/**
 * Поле WPBakery модуля «Блоки статей»: многострочный текст, который сохраняется в base64.
 *
 * Видимое `<textarea>` редактирует автор; в шорткод уходит скрытое поле `wpb_vc_param_value`,
 * которое заполняет `assets/editor-fields.js`. Классы `wpb-textarea textarea` — оформление
 * штатного многострочного поля WPBakery.
 *
 * @package Inc\Modules\ArticleBlocks
 *
 * @var string $param_name Имя атрибута шорткода
 * @var string $param_type Тип поля
 * @var string $stored     Сохранённое значение (закодированное)
 * @var string $text       Текст для редактирования
 * @var string $mode       code|table — поведение Tab
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="fs-lms-encoded-field" data-mode="<?php echo esc_attr( $mode ); ?>">
	<textarea class="wpb-textarea textarea fs-lms-encoded-field__input" rows="12" spellcheck="false" autocomplete="off"><?php echo esc_textarea( $text ); ?></textarea>
	<input type="hidden"
		name="<?php echo esc_attr( $param_name ); ?>"
		class="wpb_vc_param_value <?php echo esc_attr( $param_name . ' ' . $param_type . '_field' ); ?>"
		value="<?php echo esc_attr( $stored ); ?>">
</div>
