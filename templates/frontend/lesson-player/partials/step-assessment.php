<?php
/**
 * Контрольная-шаг плеера (T14.14): карточка с метаданными (название, лимит
 * времени, попытки) и кнопкой перехода на страницу контрольной (attempt-флоу).
 * Статус passed приходит из LessonProgressService и отражается в ленте/дереве.
 *
 * @var array  $step       Шаг из LessonPlayerService::buildView.
 * @var array  $render     Render-данные шага (LessonPlayerService::renderAssessmentData).
 * @var bool   $is_preview Признак preview-плеера курса (Фаза 5) — блокирует переход к попытке.
 * @var string $edit_url   Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;

$asm_title  = (string) ( $render['title'] ?? $step['title'] );
$asm_url    = (string) ( $render['url'] ?? '' );
$asm_passed = 'completed' === $step['status'];
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
					<svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.2l2.8 1.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
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

		<?php if ( $asm_passed ) : ?>
			<div class="vd vd-ok">
				<span class="vi"><svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
				<div><b><?php esc_html_e( 'Контрольная сдана', 'fs-lms' ); ?></b><span><?php esc_html_e( 'Результат учтён в прогрессе урока.', 'fs-lms' ); ?></span></div>
			</div>
		<?php else : ?>
			<p class="step-muted"><?php esc_html_e( 'Контрольная проходится на отдельной странице с таймером и попытками.', 'fs-lms' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $asm_url && empty( $is_preview ) ) : ?>
			<div>
				<a class="b b-pri b-lg" href="<?php echo esc_url( $asm_url ); ?>">
					<?php echo $asm_passed ? esc_html__( 'Открыть контрольную', 'fs-lms' ) : esc_html__( 'Перейти к контрольной', 'fs-lms' ); ?>
					<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 4.5 13.5 10 8 15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</a>
			</div>
		<?php elseif ( '' !== $asm_url && ! empty( $is_preview ) ) : ?>
			<div>
				<span class="b b-pri b-lg b-dis">
					<?php esc_html_e( 'Перейти к контрольной', 'fs-lms' ); ?>
				</span>
				<p class="step-muted pv-note"><?php esc_html_e( 'Предпросмотр — попытка недоступна.', 'fs-lms' ); ?></p>
			</div>
		<?php else : ?>
			<p class="step-muted"><?php esc_html_e( 'Контрольная ещё не опубликована.', 'fs-lms' ); ?></p>
		<?php endif; ?>
	</div>
</div>
