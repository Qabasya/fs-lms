<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Поле краткого описания статьи (карточка учебника, тег description).
 *
 * @var string $field_name Имя поля и ключ постметы
 * @var string $value      Сохранённое описание
 * @var int    $max_length Предел длины в символах
 */
?>
<div class="fs-lms-article-description js-article-description">
	<textarea
		id="<?php echo esc_attr( $field_name ); ?>"
		name="<?php echo esc_attr( $field_name ); ?>"
		class="widefat js-article-description__input"
		rows="3"
		maxlength="<?php echo esc_attr( (string) $max_length ); ?>"
		placeholder="Одно предложение о статье — его увидят в списке учебника"
	><?php echo esc_textarea( $value ); ?></textarea>

	<p class="description">
		<span class="js-article-description__counter"><?php echo esc_html( (string) mb_strlen( $value, 'UTF-8' ) ); ?></span>
		/ <?php echo esc_html( (string) $max_length ); ?> символов
	</p>
</div>
