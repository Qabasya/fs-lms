<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Обёртка метабокса вокруг ПОДМНОЖЕСТВА полей шаблона (BaseTemplate::renderFields()) —
 * когда состав одного шаблона распределяется по нескольким метабоксам
 * (см. .docs/Tasks.md, «тип экзамена — отдельный метабокс»).
 *
 * @var string                                $wrapper_class CSS-класс обёртки
 * @var \WP_Post                              $post          Редактируемая запись
 * @var \Inc\MetaBoxes\Templates\BaseTemplate $template      Шаблон полей
 * @var array<string, mixed>                  $values        Мета задания (PostManager::taskMeta)
 * @var string[]                              $field_ids     Какие поля шаблона рендерить
 */
?>
<div class="<?php echo esc_attr( $wrapper_class ); ?>">
	<div class="fs-lms-template-wrapper">
		<?php $template->renderFields( $post, $values, $field_ids ); ?>
	</div>
</div>
