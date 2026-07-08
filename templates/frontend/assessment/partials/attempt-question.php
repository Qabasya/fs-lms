<?php
/**
 * Один блок задания попытки (.fs-attempt-question) — общий для одностраничного
 * списка (Control) и станции-навигатора (Ege, D16.7). Разметка идентична, чтобы
 * JS (autosave/файлы/сдача) работал одинаково в обоих режимах.
 *
 * @var int                                                     $taskId
 * @var int                                                     $i          Порядковый индекс (0-based).
 * @var array<int, array{template: string, materials: array, condition: string}> $taskViews
 */
declare( strict_types=1 );

$task = get_post( $taskId );
if ( ! $task ) {
	return;
}

$taskView     = $taskViews[ (int) $taskId ] ?? array( 'template' => '', 'materials' => array(), 'condition' => '' );
$isFileAnswer = 'file_answer_task' === $taskView['template'];
?>
<div class="fs-attempt-question"
	data-task-id="<?php echo esc_attr( (string) $taskId ); ?>"
	<?php echo $isFileAnswer ? 'data-template="file_answer"' : ''; ?>>
	<div class="fs-attempt-question-number"><?php echo esc_html( (string) ( $i + 1 ) ); ?>.</div>
	<div class="fs-attempt-question-content wpc">
		<?php echo wp_kses_post( $taskView['condition'] ); ?>
	</div>

	<?php if ( $isFileAnswer && ! empty( $taskView['materials'] ) ) : ?>
		<div class="fs-attempt-materials">
			<div class="fs-attempt-materials__title">Материалы задания:</div>
			<?php foreach ( $taskView['materials'] as $material ) : ?>
				<a class="fs-attempt-materials__link"
					href="<?php echo esc_url( $material['url'] ); ?>"
					target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $material['name'] ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="fs-form-group">
		<textarea
			class="fs-attempt-answer"
			name="answer_<?php echo esc_attr( (string) $taskId ); ?>"
			rows="<?php echo $isFileAnswer ? 5 : 3; ?>"
			placeholder="<?php echo $isFileAnswer ? 'Текст решения (необязательно, если прикладываете файл)…' : 'Ваш ответ…'; ?>"
		></textarea>

		<?php if ( $isFileAnswer ) : ?>
			<div class="fs-attempt-files">
				<div class="fs-attempt-files__chips"></div>
				<div class="fs-attempt-files__controls">
					<input type="file" class="fs-attempt-files__input" hidden multiple
						accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.pdf,.doc,.docx,.pptx,.txt,.py">
					<button type="button" class="fs-btn fs-btn--secondary fs-attempt-files__add">
						📎 Прикрепить файлы
					</button>
					<span class="fs-attempt-files__status" aria-live="polite"></span>
				</div>
				<p class="fs-attempt-files__hint">
					Проверяется преподавателем вручную. Фото/PDF/документ/презентация/.py, до 20 МБ.
				</p>
			</div>
		<?php endif; ?>
	</div>
</div>
