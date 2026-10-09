<?php
/**
 * Страница результата гостя (этап 11b.3): итог, показатели, перечень заданий и разбор.
 *
 * Шорткод `[fs_lms_exam_result]` внутри страницы темы. Кнопок «Перерешать», «Решить самостоятельно» и режима тренировки нет: гость видит
 * только свою работу. Ручная часть ОГЭ — с пометкой «Проверяется преподавателем» и без балла; итог до её завершения — предварительный.
 * HTML условий приходит из банка заданий (авторский контент) и пропускается через `wp_kses_post`; всё остальное экранируется.
 *
 * @package FS LMS
 *
 * @var bool   $revealed
 * @var string $event_title
 * @var string $title
 * @var string $submitted
 * @var string $caption
 * @var bool   $preliminary
 * @var list<array<string, mixed>> $units
 * @var array{correct: int, partial: int, wrong: int, pending: int} $counts
 * @var list<array<string, mixed>> $tasks
 * @var array<string, string> $contacts
 * @var bool   $can_end_session Кнопка «Завершить сеанс»: показана, когда работа сдана
 * @var array<int, array{label: string, url?: string, current?: bool}> $crumbs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$phone = (string) ( $contacts['phone'] ?? '' );
?>

<main class="fs-lms-join-page fs-exam-result" id="fs-exam-result" data-revealed="<?php echo $revealed ? '1' : '0'; ?>">
	<?php
	if ( ! empty( $crumbs ) ) {
		include __DIR__ . '/partials/breadcrumbs.php';
	}
	?>

	<?php if ( ! $revealed ) : ?>
		<div class="fs-join-card">
			<h2 class="fs-join-card__title"><?php esc_html_e( 'Результат пока недоступен', 'fs-lms' ); ?></h2>
			<p class="fs-exam-signup__state" role="status">
				<?php esc_html_e( 'Результат появится здесь после сдачи работы.', 'fs-lms' ); ?>
				<?php if ( '' !== $phone ) : ?>
					<?php echo esc_html( sprintf( 'Вопросы — по телефону %s.', $phone ) ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php else : ?>
		<header class="fs-exam-result__head">
			<h1 class="fs-exam-result__title"><?php echo esc_html( $event_title ); ?></h1>
			<?php if ( '' !== $submitted ) : ?>
				<p class="fs-exam-result__meta"><?php echo esc_html( sprintf( 'Сдано %s', mysql2date( 'j F Y, H:i', $submitted ) ) ); ?></p>
			<?php endif; ?>
			<p class="fs-exam-result__score"><?php echo esc_html( $caption ); ?></p>
			<?php if ( $preliminary ) : ?>
				<p class="fs-exam-result__note"><?php esc_html_e( 'Предварительный результат: проверены не все задания.', 'fs-lms' ); ?></p>
			<?php endif; ?>
		</header>

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

		<?php if ( ! empty( $can_end_session ) ) : ?>
			<p class="fs-exam-result__end">
				<button type="button" class="fs-join-btn" data-exam-end-session><?php esc_html_e( 'Завершить сеанс', 'fs-lms' ); ?></button>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</main>
