<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Обёртка метабокса вокруг полей, которые рисует шаблон задания/работы/контрольной.
 *
 * @var string                          $wrapper_class CSS-класс обёртки
 * @var \WP_Post                        $post          Редактируемая запись
 * @var \Inc\MetaBoxes\Templates\BaseTemplate $template Шаблон полей
 */
?>
<div class="<?php echo esc_attr( $wrapper_class ); ?>">
	<?php $template->render( $post ); ?>
</div>
