<?php

declare( strict_types=1 );

/**
 * Разбор работы: показатели, навигация по заданиям и карточки заданий (этапы 11b.3 и 12.3).
 *
 * Общий партиал страницы результата гостя и школьного отчёта: одна разметка — одни стили (`_exam-result.scss`).
 * Данные готовит {@see \Inc\Services\Exam\GuestResultViewService}. HTML условий приходит из банка заданий (авторский контент)
 * и пропускается через `wp_kses_post`; всё остальное экранируется.
 *
 * @package FS LMS
 *
 * @var list<array<string, mixed>> $units
 * @var array{correct: int, partial: int, wrong: int, pending: int} $counts
 * @var list<array<string, mixed>> $tasks
 * @var string|null $duration_text Время, затраченное на работу («ЧЧ:ММ:СС»); пусто — не показывать.
 * @var string|null $answer_label Подпись ответа участника; по умолчанию «Ваш ответ:» (отчёт школе задаёт «Ответ ученика:»).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$answer_label = $answer_label ?? __( 'Ваш ответ:', 'fs-lms' );
?>
	<dl class="fs-exam-result__stats">
		<div class="fs-exam-result__stat is-correct"><dt><?php esc_html_e( 'Верно', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['correct']; ?></dd></div>
		<div class="fs-exam-result__stat is-partial"><dt><?php esc_html_e( 'Частично', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['partial']; ?></dd></div>
		<div class="fs-exam-result__stat is-wrong"><dt><?php esc_html_e( 'Неверно и не решено', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['wrong']; ?></dd></div>
		<?php if ( '' !== (string) ( $duration_text ?? '' ) ) : ?>
			<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Затрачено времени', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $duration_text ); ?></dd></div>
		<?php endif; ?>
		<?php if ( $counts['pending'] > 0 ) : ?>
			<div class="fs-exam-result__stat is-pending"><dt><?php esc_html_e( 'Проверяется', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['pending']; ?></dd></div>
		<?php endif; ?>
	</dl>

	<nav class="fs-exam-result__nav" aria-label="<?php esc_attr_e( 'Задания', 'fs-lms' ); ?>">
		<?php foreach ( $units as $unit ) : ?>
			<a class="fs-exam-result__chip is-<?php echo esc_attr( (string) $unit['status'] ); ?>" href="#<?php echo esc_attr( (string) $unit['anchor'] ); ?>"><?php echo esc_html( '' !== (string) $unit['number'] ? (string) $unit['number'] : '·' ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="fs-exam-result__tasks">
		<?php foreach ( $tasks as $task ) : ?>
			<article class="fs-task-card fs-exam-task is-<?php echo esc_attr( (string) $task['verdict'] ); ?>" id="<?php echo esc_attr( (string) $task['anchor'] ); ?>">
				<div class="fs-task-card__body">
				<header class="fs-exam-task__head">
					<span class="fs-exam-task__n"><?php echo esc_html( sprintf( 'Задание %d', (int) $task['n'] ) ); ?></span>
					<span class="fs-exam-task__verdict"><?php echo esc_html( (string) $task['verdict_text'] ); ?></span>
					<?php if ( is_array( $task['answer_time'] ?? null ) ) : ?>
						<span class="fs-exam-task__time" title="<?php echo esc_attr( (string) $task['answer_time']['title'] ); ?>"><?php echo esc_html( (string) $task['answer_time']['text'] ); ?></span>
					<?php endif; ?>
					<?php if ( null !== $task['score'] ) : ?>
						<span class="fs-exam-task__score"><?php echo esc_html( rtrim( rtrim( number_format( (float) $task['score'], 1, '.', '' ), '0' ), '.' ) . ( null !== $task['max_score'] ? ' / ' . rtrim( rtrim( number_format( (float) $task['max_score'], 1, '.', '' ), '0' ), '.' ) : '' ) ); ?></span>
					<?php endif; ?>
				</header>

				<div class="fs-task-condition fs-exam-task__cond"><?php echo wp_kses_post( (string) $task['condition'] ); ?></div>

				<?php if ( 'pending' === $task['verdict'] ) : ?>
					<p class="fs-exam-task__note"><?php esc_html_e( 'Проверяется преподавателем.', 'fs-lms' ); ?></p>
				<?php endif; ?>

				<?php if ( '' !== (string) $task['answer'] || '' === (string) $task['code'] ) : ?>
					<p class="fs-exam-task__line"><span class="fs-exam-task__label"><?php echo esc_html( $answer_label ); ?></span> <?php echo '' !== (string) $task['answer'] ? esc_html( (string) $task['answer'] ) : '—'; ?></p>
				<?php endif; ?>

				<?php if ( '' !== (string) $task['code'] ) : ?>
					<pre class="fs-exam-task__code"><code><?php echo esc_html( (string) $task['code'] ); ?></code></pre>
				<?php endif; ?>

				<?php foreach ( (array) $task['files'] as $file ) : ?>
					<p class="fs-exam-task__line"><a href="<?php echo esc_url( (string) ( $file['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) ( $file['name'] ?? __( 'Файл', 'fs-lms' ) ) ); ?></a></p>
				<?php endforeach; ?>

				<?php if ( '' !== (string) $task['correct'] ) : ?>
					<p class="fs-exam-task__line is-correct"><span class="fs-exam-task__label"><?php esc_html_e( 'Правильный ответ:', 'fs-lms' ); ?></span> <?php echo esc_html( (string) $task['correct'] ); ?></p>
				<?php endif; ?>

				</div>

				<?php
				// Решение — тот же блок вкладок, что и в тренажёре: кнопка в панели карточки, листинг кода
				// разворачивается в редактор с подсветкой (`code.js-code`, code-block.js). Идентификатор панели —
				// по якорю задания: на странице отчёта карточек много.
				if ( is_array( $task['solution'] ) ) :
					$panel_id = 'fs-exam-solution-' . (string) $task['anchor'];
					?>
					<div class="fs-task-tabs">
						<div class="fs-tabs-toolbar">
							<div class="fs-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Материалы задания', 'fs-lms' ); ?>">
								<button type="button" id="<?php echo esc_attr( $panel_id . '-btn' ); ?>" class="fs-tab-btn" role="tab" aria-selected="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-tab="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Показать решение', 'fs-lms' ); ?></button>
							</div>
						</div>
						<div class="fs-tabs-content">
							<div id="<?php echo esc_attr( $panel_id ); ?>" class="fs-tab-panel" role="tabpanel" aria-labelledby="<?php echo esc_attr( $panel_id . '-btn' ); ?>" data-panel="<?php echo esc_attr( $panel_id ); ?>">
								<?php if ( '' !== trim( (string) ( $task['solution']['html'] ?? '' ) ) ) : ?>
									<?php echo wp_kses_post( (string) $task['solution']['html'] ); ?>
								<?php endif; ?>
								<?php if ( '' !== trim( (string) ( $task['solution']['code'] ?? '' ) ) ) : ?>
									<pre><code class="js-code" data-lang="Python"><?php echo esc_html( (string) $task['solution']['code'] ); ?></code></pre>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
