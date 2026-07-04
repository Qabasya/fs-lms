<?php
/**
 * Работа-шаг плеера (D19, T14.10): воркбар с прогрессом и «Завершить работу»,
 * стек карточек задач с виджетами и чипами «сохранён/нет ответа».
 * Прохождение/результаты переключает step-work.js; сдача — SubmitBatchWork.
 *
 * @var array  $step       Шаг из LessonPlayerService::buildView.
 * @var array  $render     Render-данные шага (LessonPlayerService::renderWorkData).
 * @var bool   $is_preview Признак preview-плеера курса (Фаза 5) — блокирует «Завершить работу».
 * @var string $edit_url   Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;

if ( empty( $render['work_found'] ) ) : ?>
	<div class="card16">
		<div class="kick">
			<span class="tbadge" data-step-type="work"><?php echo esc_html( StepType::Work->label() ); ?></span>
			<?php if ( ! empty( $edit_url ) ) : ?>
				<a class="b b-gh b-sm pv-edit" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></a>
			<?php endif; ?>
		</div>
		<h2><?php echo esc_html( $step['title'] ); ?></h2>
		<div class="gap16">
			<p class="step-muted"><?php esc_html_e( 'Работа недоступна. Обратитесь к преподавателю.', 'fs-lms' ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;

$work_tasks     = is_array( $render['tasks'] ?? null ) ? $render['tasks'] : array();
$work_has_sub   = ! empty( $render['submission'] );
$work_state     = array(
	'work_id'      => (int) $render['ref'],
	'submission'   => $render['submission'] ?? null,
	'task_results' => $render['task_results'] ?? array(),
);
$work_meta_line = sprintf(
	/* translators: 1: work type label, 2: task count, 3: total points */
	__( '%1$s · задач: %2$d · баллов: %3$d · ответы можно менять до завершения', 'fs-lms' ),
	$render['work_type_label'],
	(int) $render['task_count'],
	(int) $render['total_points']
);
?>
<div class="work-root"
	data-work-id="<?php echo esc_attr( (string) $render['ref'] ); ?>"
	data-state='<?php echo esc_attr( (string) wp_json_encode( $work_state ) ); ?>'>

	<div class="work-progress" data-work-progress-root <?php echo $work_has_sub ? 'hidden' : ''; ?>>
		<div class="a-workbar">
			<span class="tbadge" data-step-type="work"><?php echo esc_html( StepType::Work->label() ); ?></span>
			<div class="wb-t">
				<b><?php echo esc_html( (string) $render['title'] ); ?></b>
				<span><?php echo esc_html( $work_meta_line ); ?></span>
			</div>
			<span class="wb-sp"></span>
			<?php if ( ! empty( $edit_url ) ) : ?>
				<a class="b b-gh b-sm pv-edit" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></a>
			<?php endif; ?>
			<div class="a-prog">
				<span class="ap-txt" data-work-prog-txt></span>
				<span class="ap-bar"><span data-work-prog-bar></span></span>
			</div>
			<button type="button" class="b b-pri" data-work-finish>
				<svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M5 17V3.5M5 4h9.5l-2 3 2 3H5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<?php esc_html_e( 'Завершить работу', 'fs-lms' ); ?>
			</button>
		</div>

		<?php if ( ! empty( $is_preview ) ) : ?>
			<p class="step-muted pv-note"><?php esc_html_e( 'Это предпросмотр — ответы не сохраняются.', 'fs-lms' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $render['instructions'] ) ) : ?>
			<div class="a-task work-instructions">
				<div class="wpc"><?php echo wp_kses_post( (string) $render['instructions'] ); ?></div>
			</div>
		<?php endif; ?>

		<div class="wstack">
			<?php foreach ( $work_tasks as $work_i => $work_task ) : ?>
				<div class="a-task"
					data-task-id="<?php echo esc_attr( (string) $work_task['task_id'] ); ?>"
					data-auto="<?php echo esc_attr( $work_task['auto_grade'] ? '1' : '0' ); ?>"
					data-title="<?php echo esc_attr( (string) $work_task['title'] ); ?>">
					<div class="th">
						<span class="tkn"><?php echo esc_html( (string) ( $work_i + 1 ) ); ?></span>
						<b><?php echo esc_html( (string) $work_task['title'] ); ?></b>
						<span class="stc stc-none" data-task-chip><?php esc_html_e( 'Нет ответа', 'fs-lms' ); ?></span>
						<span class="pts"><?php esc_html_e( '1 балл', 'fs-lms' ); ?></span>
					</div>

					<?php if ( ! empty( $work_task['condition_html'] ) && is_string( $work_task['condition_html'] ) ) : ?>
						<div class="q wpc"><?php echo wp_kses_post( $work_task['condition_html'] ); ?></div>
					<?php endif; ?>

					<div class="tgap">
						<div class="fs-task-widget"
							data-template="<?php echo esc_attr( (string) $work_task['template'] ); ?>"
							data-widget='<?php echo esc_attr( (string) wp_json_encode( $work_task['widget_data'] ?? array() ) ); ?>'></div>
						<span class="wnote">
							<?php
							echo $work_task['auto_grade']
								? esc_html__( 'Ответ сохраняется автоматически · правильность станет видна после завершения', 'fs-lms' )
								: esc_html__( 'Развёрнутый ответ оценивает преподаватель после завершения работы', 'fs-lms' );
							?>
						</span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="work-results" data-work-results-root <?php echo $work_has_sub ? '' : 'hidden'; ?>>
		<!-- Наполняет step-work.js: сводка + карточки вердиктов (T14.11) -->
	</div>
</div>
