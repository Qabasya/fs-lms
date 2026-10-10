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
 * @var array{courses: array, articles: array, articles_url: string}|null $sidebar Сайдбар: заглушка «Курсы» и статьи по ошибкам (11b.3.1)
 * @var array<string, string> $contacts
 * @var bool   $can_end_session Кнопка «Завершить сеанс»: показана, когда работа сдана
 * @var array<int, array{label: string, url?: string, current?: bool}> $crumbs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$phone = (string) ( $contacts['phone'] ?? '' );

$sidebar_data = (array) ( $sidebar ?? array() );
$has_sidebar  = ! empty( $sidebar_data['courses'] ) || ! empty( $sidebar_data['articles'] );
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

		<div class="fs-exam-result__layout<?php echo $has_sidebar ? ' fs-exam-result__layout--aside' : ''; ?>">
			<div class="fs-exam-result__body">
				<?php include __DIR__ . '/partials/exam-review.php'; ?>

				<?php if ( ! empty( $can_end_session ) ) : ?>
					<p class="fs-exam-result__end">
						<button type="button" class="fs-join-btn" data-exam-end-session><?php esc_html_e( 'Завершить сеанс', 'fs-lms' ); ?></button>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $has_sidebar ) : ?>
				<aside class="fs-task-sidebar fs-exam-result__aside">
					<?php
					$sidebar_courses      = $sidebar_data['courses'];
					$sidebar_articles     = $sidebar_data['articles'];
					$sidebar_articles_url = (string) $sidebar_data['articles_url'];
					include __DIR__ . '/partials/sidebar-courses.php';
					include __DIR__ . '/partials/sidebar-articles.php';
					?>
				</aside>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</main>
