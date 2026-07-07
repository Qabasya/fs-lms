<?php
/**
 * Задача-шаг плеера (T14.7/T14.8): условие + виджет (step-task.js),
 * счётчик попыток, подсказка, кнопка «Ответить», эталон после исчерпания (D20).
 *
 * @var array $step       Шаг из LessonPlayerService::buildView.
 * @var array $render     Render-данные шага.
 * @var bool  $is_preview Признак preview-плеера курса (Фаза 5) — блокирует «Ответить».
 * @var string $edit_url  Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;
?>
<div class="card16">
	<div class="kick">
		<span class="tbadge" data-step-type="<?php echo esc_attr( $step['type'] ); ?>">
			<?php echo esc_html( StepType::fromValueOrDefault( $step['type'] )->label() ); ?>
		</span>
		<?php if ( ! empty( $edit_url ) ) : ?>
			<a class="b b-gh b-sm pv-edit" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></a>
		<?php endif; ?>
	</div>
	<h2><?php echo esc_html( $step['title'] ); ?></h2>

	<div class="gap16">
		<?php if ( ! empty( $render['auto_grade'] ) ) : ?>
			<?php
			$task_tmpl    = (string) ( $render['template'] ?? '' );
			// data-done блокирует ВИДЖЕТ (task-widget.js) — в preview виджет должен
			// оставаться активным, поэтому здесь preview НЕ учитывается.
			$task_is_done = in_array( $step['status'], array( 'completed', 'failed' ), true );

			// Условие(я)
			if ( 'triple_task' === $task_tmpl && is_array( $render['condition_html'] ) ) :
				foreach ( $render['condition_html'] as $task_num => $task_cond ) :
					echo '<div class="fs-task-subpart"><h3 class="fs-task-subpart__label">'
						. esc_html__( 'Задание №', 'fs-lms' ) . esc_html( (string) $task_num )
						. '</h3><div class="fs-task-subpart__body wpc">'
						. wp_kses_post( (string) $task_cond )
						. '</div></div>';
				endforeach;
			elseif ( 'fill_task' !== $task_tmpl && ! empty( $render['condition_html'] ) ) :
				echo '<div class="fs-task-condition wpc">' . wp_kses_post( (string) $render['condition_html'] ) . '</div>';
			endif;
			?>

			<div class="fs-task-widget"
				data-template="<?php echo esc_attr( $task_tmpl ); ?>"
				data-widget='<?php echo esc_attr( (string) wp_json_encode( $render['widget_data'] ?? array() ) ); ?>'
				<?php echo $task_is_done ? 'data-done="1"' : ''; ?>
				<?php echo ! empty( $render['correct_answer'] ) ? 'data-correct-text="' . esc_attr( (string) $render['correct_answer'] ) . '"' : ''; ?>
				<?php echo ! empty( $render['correct_answer_ids'] ) ? "data-correct-ids='" . esc_attr( (string) wp_json_encode( $render['correct_answer_ids'] ) ) . "'" : ''; ?>></div>

			<?php
			$task_max_att  = (int) ( $render['settings']['max_attempts'] ?? 0 );
			$task_used_att = (int) ( $render['attempts_used'] ?? 0 );
			if ( $task_max_att > 0 ) :
			?>
			<div class="fs-attempt-indicator"
				data-used="<?php echo esc_attr( (string) $task_used_att ); ?>"
				data-max="<?php echo esc_attr( (string) $task_max_att ); ?>">
				<?php
				printf(
					/* translators: 1: attempts used, 2: max attempts */
					esc_html__( 'Попыток использовано: %1$d из %2$d', 'fs-lms' ),
					$task_used_att,
					$task_max_att
				);
				?>
			</div>
			<?php endif; ?>

			<div class="fs-task-footer">
				<button type="button"
					class="b b-pri fs-task-submit"
					data-step="<?php echo esc_attr( $step['key'] ); ?>"
					<?php echo ! empty( $is_preview ) && ! empty( $render['ref'] ) ? 'data-preview-ref="' . esc_attr( (string) $render['ref'] ) . '"' : ''; ?>
					<?php echo $task_is_done ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Ответить', 'fs-lms' ); ?>
				</button>
				<div class="fs-task-result" aria-live="polite"></div>
				<?php if ( ! empty( $is_preview ) ) : ?>
					<p class="step-muted pv-note"><?php esc_html_e( 'Это предпросмотр — ответ не сохраняется.', 'fs-lms' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $render['hint_html'] ) ) : ?>
			<details class="fs-hint"<?php echo ! empty( $render['reveal_hint'] ) ? ' open' : ''; ?>>
				<summary class="fs-hint__toggle"><?php esc_html_e( 'Подсказка', 'fs-lms' ); ?></summary>
				<div class="fs-hint__body"><?php echo wp_kses_post( (string) $render['hint_html'] ); ?></div>
			</details>
			<?php endif; ?>

		<?php else : ?>
			<?php if ( ! empty( $render['condition_html'] ) && is_string( $render['condition_html'] ) ) : ?>
				<div class="fs-task-condition wpc"><?php echo wp_kses_post( $render['condition_html'] ); ?></div>
			<?php endif; ?>
			<p class="step-muted">
				<?php esc_html_e( 'Это задание проверяется вручную. Выполните его и отметьте шаг кнопкой «Далее» — преподаватель проверит работу.', 'fs-lms' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
