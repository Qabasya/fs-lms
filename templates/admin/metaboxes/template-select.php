<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Метабокс выбора шаблона редактора задачи.
 *
 * @var string                    $name      Имя поля (ключ меты)
 * @var string                    $current   Текущий шаблон
 * @var \Inc\Contracts\FieldInterface[]|array $templates Доступные шаблоны
 */
?>
<select name="<?php echo esc_attr( $name ); ?>" class="fs-lms-template-select">
	<?php foreach ( $templates as $template ) : ?>
		<option value="<?php echo esc_attr( $template->get_id() ); ?>" <?php selected( $current, $template->get_id() ); ?>>
			<?php echo esc_html( $template->get_name() ); ?>
		</option>
	<?php endforeach; ?>
</select>
