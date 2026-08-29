<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Поле «Источник» — исходный номер задания из бумажного сборника.
 *
 * @var string $field_name Имя поля и ключ постметы
 * @var string $value      Сохранённое значение
 */
?>
<input
	type="text"
	id="<?php echo esc_attr( $field_name ); ?>"
	name="<?php echo esc_attr( $field_name ); ?>"
	class="widefat"
	value="<?php echo esc_attr( $value ); ?>"
	placeholder="Например: сборник Ященко, №7"
/>
