<?php
/**
 * Контрольная-шаг плеера (T14.14): карточка с метаданными (название, лимит
 * времени, попытки) и кнопкой перехода на страницу контрольной (attempt-флоу).
 * Статус passed приходит из LessonProgressService и отражается в ленте/дереве.
 *
 * #5 (D-2): в preview-плеере контрольная прорешивается ИНЛАЙНОМ (тот же UI, что
 * у работы: стек задач + «Завершить и проверить» → PreviewCheckAssessment),
 * без attempt-флоу/таймера/сохранения. Драйвит step-work.js (он обрабатывает и
 * assessment-панель при наличии .work-root).
 *
 * @var array  $step       Шаг из LessonPlayerService::buildView.
 * @var array  $render     Render-данные шага (renderAssessmentData).
 * @var bool   $is_preview Признак preview-плеера курса (Фаза 5).
 * @var string $edit_url   Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;
use Inc\Enums\Ui\Icon;

$asm_title   = (string) ( $render['title'] ?? $step['title'] );
$asm_url     = (string) ( $render['url'] ?? '' );
$asm_passed  = 'completed' === $step['status'];
$asm_tasks   = is_array( $render['tasks'] ?? null ) ? $render['tasks'] : array();
$asm_preview = ! empty( $is_preview ) && ! empty( $render['assessment_found'] );
?>
<div class="card16">
	<div class="kick">
		<span class="tbadge" data-step-type="assessment"><?php echo esc_html( StepType::fromValueOrDefault( $step['type'] )->label() ); ?></span>
		<?php if ( ! empty( $edit_url ) ) : ?>
			<a class="b b-gh b-sm pv-edit" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></a>
		<?php endif; ?>
	</div>
	<h2><?php echo esc_html( $asm_title ); ?></h2>

	<div class="gap16">
		<div class="asm-meta">
			<?php if ( ! empty( $render['time_limit_min'] ) ) : ?>
				<span class="asm-chip">
					<?php echo Icon::Clock->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php
					printf(
						/* translators: %d: minutes */
						esc_html__( 'Лимит: %d мин', 'fs-lms' ),
						(int) $render['time_limit_min']
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $render['max_attempts'] ) ) : ?>
				<span class="asm-chip">
					<?php
					printf(
						/* translators: %d: attempts count */
						esc_html__( 'Попыток: %d', 'fs-lms' ),
						(int) $render['max_attempts']
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( $asm_preview ) : ?>
			<p class="step-muted pv-note"><?php esc_html_e( 'Предпросмотр контрольной — прорешайте задания, результат не сохраняется.', 'fs-lms' ); ?></p>

			<div class="work-root"
				data-work-id="<?php echo esc_attr( (string) $render['ref'] ); ?>"
				data-state='<?php echo esc_attr( (string) wp_json_encode( array( 'work_id' => (int) $render['ref'], 'submission' => null, 'task_results' => array() ) ) ); ?>'>

				<div class="work-progress" data-work-progress-root>
					<div class="a-workbar">
						<span class="tbadge" data-step-type="assessment"><?php echo esc_html( StepType::fromValueOrDefault( $step['type'] )->label() ); ?></span>
						<div class="wb-t">
							<b><?php echo esc_html( $asm_title ); ?></b>
							<span>
								<?php
								printf(
									/* translators: 1: task count, 2: total points */
									esc_html__( 'Задач: %1$d · баллов: %2$d · ответы можно менять до завершения', 'fs-lms' ),
									(int) $render['task_count'],
									(int) $render['total_points']
								);
								?>
							</span>
						</div>
						<span class="wb-sp"></span>
						<div class="a-prog">
							<span class="ap-txt" data-work-prog-txt></span>
							<span class="ap-bar"><span data-work-prog-bar></span></span>
						</div>
						<button type="button" class="b b-pri" data-work-finish>
							<?php echo Icon::Flag->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Завершить и проверить', 'fs-lms' ); ?>
						</button>
					</div>

					<div class="wstack">
						<?php foreach ( $asm_tasks as $asm_i => $asm_task ) : ?>
							<div class="a-task"
								data-task-id="<?php echo esc_attr( (string) $asm_task['task_id'] ); ?>"
								data-auto="<?php echo esc_attr( $asm_task['auto_grade'] ? '1' : '0' ); ?>"
								data-title="<?php echo esc_attr( (string) $asm_task['title'] ); ?>">
								<div class="th">
									<span class="tkn"><?php echo esc_html( (string) ( $asm_i + 1 ) ); ?></span>
									<b><?php echo esc_html( (string) $asm_task['title'] ); ?></b>
									<span class="stc stc-none" data-task-chip><?php esc_html_e( 'Нет ответа', 'fs-lms' ); ?></span>
								</div>

								<?php if ( ! empty( $asm_task['condition_html'] ) && is_string( $asm_task['condition_html'] ) ) : ?>
									<div class="q wpc"><?php echo wp_kses_post( $asm_task['condition_html'] ); ?></div>
								<?php endif; ?>

								<div class="tgap">
									<div class="fs-task-widget"
										data-template="<?php echo esc_attr( (string) $asm_task['template'] ); ?>"
										data-widget='<?php echo esc_attr( (string) wp_json_encode( $asm_task['widget_data'] ?? array() ) ); ?>'></div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="work-results" data-work-results-root hidden></div>
			</div>

		<?php else : ?>
			<?php if ( $asm_passed ) : ?>
				<div class="vd vd-ok">
					<span class="vi"><?php echo Icon::Check->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div><b><?php esc_html_e( 'Контрольная сдана', 'fs-lms' ); ?></b><span><?php esc_html_e( 'Результат учтён в прогрессе урока.', 'fs-lms' ); ?></span></div>
				</div>
			<?php else : ?>
				<p class="step-muted"><?php esc_html_e( 'Контрольная проходится на отдельной странице с таймером и попытками.', 'fs-lms' ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $asm_url ) : ?>
				<div>
					<a class="b b-pri b-lg" href="<?php echo esc_url( $asm_url ); ?>">
						<?php echo $asm_passed ? esc_html__( 'Открыть контрольную', 'fs-lms' ) : esc_html__( 'Перейти к контрольной', 'fs-lms' ); ?>
						<?php echo Icon::ChevronRight->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				</div>
			<?php else : ?>
				<p class="step-muted"><?php esc_html_e( 'Контрольная ещё не опубликована.', 'fs-lms' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
