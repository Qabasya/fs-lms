<?php
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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<dl class="fs-exam-result__stats">
		<div class="fs-exam-result__stat is-correct"><dt><?php esc_html_e( 'Верно', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['correct']; ?></dd></div>
		<div class="fs-exam-result__stat is-partial"><dt><?php esc_html_e( 'Частично', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['partial']; ?></dd></div>
		<div class="fs-exam-result__stat is-wrong"><dt><?php esc_html_e( 'Неверно и не решено', 'fs-lms' ); ?></dt><dd><?php echo (int) $counts['wrong']; ?></dd></div>
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
			<article class="fs-exam-task is-<?php echo esc_attr( (string) $task['verdict'] ); ?>" id="<?php echo esc_attr( (string) $task['anchor'] ); ?>">
				<header class="fs-exam-task__head">
					<span class="fs-exam-task__n"><?php echo esc_html( sprintf( 'Задание %d', (int) $task['n'] ) ); ?></span>
					<span class="fs-exam-task__verdict"><?php echo esc_html( (string) $task['verdict_text'] ); ?></span>
					<?php if ( null !== $task['score'] ) : ?>
						<span class="fs-exam-task__score"><?php echo esc_html( rtrim( rtrim( number_format( (float) $task['score'], 1, '.', '' ), '0' ), '.' ) . ( null !== $task['max_score'] ? ' / ' . rtrim( rtrim( number_format( (float) $task['max_score'], 1, '.', '' ), '0' ), '.' ) : '' ) ); ?></span>
					<?php endif; ?>
				</header>

				<div class="fs-exam-task__cond"><?php echo wp_kses_post( (string) $task['condition'] ); ?></div>

				<?php if ( 'pending' === $task['verdict'] ) : ?>
					<p class="fs-exam-task__note"><?php esc_html_e( 'Проверяется преподавателем.', 'fs-lms' ); ?></p>
				<?php endif; ?>

				<?php if ( '' !== (string) $task['answer'] || '' === (string) $task['code'] ) : ?>
					<p class="fs-exam-task__line"><span class="fs-exam-task__label"><?php esc_html_e( 'Ваш ответ:', 'fs-lms' ); ?></span> <?php echo '' !== (string) $task['answer'] ? esc_html( (string) $task['answer'] ) : '—'; ?></p>
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

				<?php if ( is_array( $task['solution'] ) ) : ?>
					<details class="fs-exam-task__solution">
						<summary><?php esc_html_e( 'Показать решение', 'fs-lms' ); ?></summary>
						<?php if ( '' !== trim( (string) ( $task['solution']['html'] ?? '' ) ) ) : ?>
							<div><?php echo wp_kses_post( (string) $task['solution']['html'] ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== trim( (string) ( $task['solution']['code'] ?? '' ) ) ) : ?>
							<pre class="fs-exam-task__code"><code><?php echo esc_html( (string) $task['solution']['code'] ); ?></code></pre>
						<?php endif; ?>
					</details>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
