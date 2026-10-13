<?php
/**
 * Виртуальный первый шаг занятия: «Трансляция» / «Запись занятия» (в уроке не хранится,
 * строит LessonPlayerService::liveStep). Два состояния:
 *
 * - до и во время занятия — кнопка «Подключиться к трансляции» (ссылка группы);
 * - после занятия — запись из хранилища тем же видео-хромом, что у video-шага
 *   (video-chrome.php), либо кнопка «Открыть запись занятия» на внешнюю ссылку
 *   (не встраивается). При записи из хранилища ссылка — запасной вариант: её
 *   показывает step-video.js, если видео не загрузилось.
 *
 * Преподаватель (teacher-режим) вставляет внешнюю ссылку прямо здесь — форма
 * `[data-recording-form]`, поведение — src/js/player/step-broadcast.js.
 *
 * @var array     $step       Шаг из LessonPlayerService::buildView.
 * @var array     $render     Render-данные шага (StepContentRenderer::renderBroadcastData).
 * @var array     $view       Данные плеера (group_lesson_id — для формы преподавателя).
 * @var bool|null $is_teacher Teacher-режим занятия.
 * @var bool      $is_preview Preview курса — занятия нет, формы нет.
 * @var string    $edit_url   Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;
use Inc\Enums\Ui\Icon;

$bc_after    = 'after' === ( $render['phase'] ?? 'live' );
$video_url   = (string) ( $render['video_url'] ?? '' );
$bc_link     = (string) ( $render['record_link'] ?? '' );
$stream_url  = (string) ( $render['stream_url'] ?? '' );
$video_chaps = array(); // главы трансляции не поддерживаются.
$bc_teacher  = ! empty( $is_teacher ) && empty( $is_preview );
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

	<div class="gap16 bc-body">
		<?php if ( ! $bc_after ) : ?>
			<?php if ( '' !== $stream_url ) : ?>
				<div class="bc-actions">
					<a class="b b-pri b-lg" href="<?php echo esc_url( $stream_url ); ?>" target="_blank" rel="noopener">
						<?php echo Icon::Play->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Подключиться к трансляции', 'fs-lms' ); ?>
					</a>
				</div>
			<?php else : ?>
				<p class="step-muted"><?php esc_html_e( 'У группы не задана ссылка на трансляцию — укажите её в настройках группы (Группы → редактировать). Ученики этот шаг не увидят.', 'fs-lms' ); ?></p>
			<?php endif; ?>

		<?php elseif ( '' !== $video_url ) : ?>
			<?php include __DIR__ . '/video-chrome.php'; ?>
			<div class="bc-fallback" data-bc-fallback hidden>
				<?php if ( '' !== $bc_link ) : ?>
					<p class="step-muted"><?php esc_html_e( 'Запись не загрузилась — откройте её по ссылке.', 'fs-lms' ); ?></p>
					<div class="bc-actions">
						<a class="b b-pri" href="<?php echo esc_url( $bc_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Открыть запись занятия', 'fs-lms' ); ?></a>
					</div>
				<?php else : ?>
					<p class="step-muted"><?php esc_html_e( 'Запись временно недоступна. Попробуйте позже.', 'fs-lms' ); ?></p>
				<?php endif; ?>
			</div>

		<?php elseif ( '' !== $bc_link ) : ?>
			<div class="bc-actions">
				<a class="b b-pri b-lg" href="<?php echo esc_url( $bc_link ); ?>" target="_blank" rel="noopener">
					<?php echo Icon::Play->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Открыть запись занятия', 'fs-lms' ); ?>
				</a>
			</div>

		<?php else : ?>
			<p class="step-muted"><?php esc_html_e( 'Записи пока нет. Вставьте ссылку на неё ниже — ученики увидят этот шаг первым в уроке.', 'fs-lms' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $is_preview ) ) : ?>
			<?php // Предпросмотр курса: занятия нет, значит и записи — показываем, где она появится у ученика. ?>
			<div class="bc-actions">
				<button type="button" class="b b-lg b-dis" disabled>
					<?php echo Icon::Play->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Открыть запись занятия', 'fs-lms' ); ?>
				</button>
			</div>
			<p class="step-muted"><?php esc_html_e( 'Ссылку на запись добавляет преподаватель в группе после занятия — в предпросмотре её нет.', 'fs-lms' ); ?></p>
		<?php endif; ?>

		<?php if ( $bc_teacher ) : ?>
			<form class="bc-rec" data-recording-form data-group-lesson-id="<?php echo esc_attr( (string) ( $view['group_lesson_id'] ?? 0 ) ); ?>">
				<label class="bc-rec-label" for="bcRecLink-<?php echo esc_attr( $step['key'] ); ?>"><?php esc_html_e( 'Ссылка на запись занятия (видна ученикам после занятия)', 'fs-lms' ); ?></label>
				<div class="bc-rec-row">
					<input type="url" class="bc-rec-input" id="bcRecLink-<?php echo esc_attr( $step['key'] ); ?>" name="recording_link" value="<?php echo esc_attr( $bc_link ); ?>" placeholder="https://…">
					<button type="submit" class="b b-pri"><?php esc_html_e( 'Сохранить', 'fs-lms' ); ?></button>
					<?php if ( '' !== $bc_link ) : ?>
						<button type="button" class="b b-gh" data-recording-clear><?php esc_html_e( 'Убрать', 'fs-lms' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		<?php endif; ?>
	</div>
</div>
