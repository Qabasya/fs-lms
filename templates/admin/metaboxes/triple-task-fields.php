<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Поля шаблона «Связка 19-21 (Теория игр)»: три блока (условие + ответ) + общий код.
 *
 * Разметка вынесена из ThreeInOneTemplate (Т13А): классы — в admin-SCSS
 * (_metabox-triple.scss), инлайновых стилей нет. Тот же HTML отдаётся
 * inline-модалке задач по AJAX (GetTaskEditorForm) — имена полей не менять.
 *
 * @var \Inc\MetaBoxes\Templates\ThreeInOneTemplate $template Шаблон (рендер полей)
 * @var \WP_Post                                    $post     Редактируемая запись
 * @var array<string, mixed>                        $values   Мета задания
 */
?>
<div class="fs-lms-template-wrapper" id="template-<?php echo esc_attr( $template->get_id() ); ?>">
	<?php foreach ( array( '19', '20', '21' ) as $fs_triple_num ) : ?>
		<div class="fs-triple-section">
			<h4>Задание №<?php echo esc_html( $fs_triple_num ); ?></h4>
			<?php $template->renderField( "task_{$fs_triple_num}_condition", $post, $values ); ?>
			<?php $template->renderField( "task_{$fs_triple_num}_answer", $post, $values ); ?>
		</div>
	<?php endforeach; ?>

	<h3>Программное решение</h3>
	<?php $template->renderField( 'task_code', $post, $values ); ?>
</div>
