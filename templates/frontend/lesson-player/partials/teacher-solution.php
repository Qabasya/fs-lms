<?php
/**
 * Эталон задачи для преподавателя («Показать решение», teacher-режим плеера).
 *
 * Раскрывающийся блок: правильный ответ + авторское решение + листинг кода
 * (у шаблонов с полем «Код», `TaskTemplate::hasCodeField()`). Данные собирает
 * `TaskSolutionService::forTask()`; в `render.solution` (task-шаг) и
 * `tasks[].solution` (задачи work-шага) их кладут `LessonPlayerService` —
 * только в teacher-режиме — и `CoursePreviewService` — в предпросмотре курса,
 * куда пускает лишь `CoursePreviewAccessGuard`. В ученическом view ключа
 * `solution` не бывает, поэтому партиал ему не подключается никогда.
 *
 * @var array{answer:string, html:string, code:string} $teacher_solution
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

?>
<details class="fs-solution">
	<summary class="fs-solution__toggle">
		<?php echo Icon::Check->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php esc_html_e( 'Показать решение', 'fs-lms' ); ?>
	</summary>
	<div class="fs-solution__body">
		<?php if ( '' !== (string) $teacher_solution['answer'] ) : ?>
			<div class="fs-solution__row">
				<span class="fs-solution__label"><?php esc_html_e( 'Правильный ответ', 'fs-lms' ); ?></span>
				<span class="fs-solution__answer"><?php echo esc_html( (string) $teacher_solution['answer'] ); ?></span>
			</div>
		<?php endif; ?>
		<?php if ( '' !== (string) $teacher_solution['html'] ) : ?>
			<div class="fs-solution__row">
				<span class="fs-solution__label"><?php esc_html_e( 'Решение', 'fs-lms' ); ?></span>
				<div class="fs-solution__html wpc"><?php echo \Inc\Shared\SafeHtml::post( (string) $teacher_solution['html'] ); ?></div>
			</div>
		<?php endif; ?>
		<?php if ( '' !== (string) ( $teacher_solution['code'] ?? '' ) ) : ?>
			<?php // Листинг разворачивает code-block.js в собственный редактор с рамкой — строке своя плашка не нужна. ?>
			<div class="fs-solution__row fs-solution__row--code">
				<span class="fs-solution__label"><?php esc_html_e( 'Код', 'fs-lms' ); ?></span>
				<pre><code class="js-code" data-lang="Python"><?php echo esc_html( (string) $teacher_solution['code'] ); ?></code></pre>
			</div>
		<?php endif; ?>
	</div>
</details>
