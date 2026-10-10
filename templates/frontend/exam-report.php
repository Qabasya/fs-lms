<?php
/**
 * Школьный отчёт (этап 12.3): таблица результатов выбранных участников и разбор одного из них под таблицей. Только чтение.
 *
 * Шорткод `[fs_lms_exam_report]` внутри страницы темы. В разметке нет телефона, мессенджера, ключей, ссылок входа и результата,
 * идентификаторов попыток и участий, кнопок оценивания, CSV и печати: строка раскрывается по порядковому номеру в отчёте (`?row=`; `p` занят WordPress — это ID записи).
 *
 * @package FS LMS
 *
 * @var string $title
 * @var string $event
 * @var string $page_url
 * @var list<array{n: int, name: string, status: string, caption: string, open: bool}> $rows
 * @var array{participants: int, average: string, pending: int} $stats
 * @var array<string, mixed>|null $review
 * @var array<int, array{label: string, url?: string, current?: bool}> $crumbs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<main class="fs-lms-join-page fs-exam-result fs-exam-report" id="fs-exam-report" data-review="<?php echo null !== $review ? '1' : '0'; ?>">
	<?php
	if ( ! empty( $crumbs ) ) {
		include __DIR__ . '/partials/breadcrumbs.php';
	}
	?>

	<header class="fs-exam-result__head">
		<h1 class="fs-exam-result__title"><?php echo esc_html( $title ); ?></h1>
		<p class="fs-exam-result__meta"><?php echo esc_html( $event ); ?></p>
	</header>

	<dl class="fs-exam-result__stats">
		<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Участников', 'fs-lms' ); ?></dt><dd><?php echo (int) $stats['participants']; ?></dd></div>
		<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Средний итог', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['average'] ); ?></dd></div>
		<div class="fs-exam-result__stat is-pending"><dt><?php esc_html_e( 'Ждут проверки', 'fs-lms' ); ?></dt><dd><?php echo (int) $stats['pending']; ?></dd></div>
	</dl>

	<table class="fs-exam-report__table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Участник', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'Работа', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'Итог', 'fs-lms' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr class="<?php echo $row['open'] ? '' : 'is-placeholder'; ?><?php echo null !== $review && (int) $review['row'] === (int) $row['n'] ? ' is-current' : ''; ?>">
					<td><?php echo esc_html( $row['name'] ); ?></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
					<td><?php echo esc_html( $row['caption'] ); ?></td>
					<td>
						<?php if ( $row['open'] ) : ?>
							<a class="fs-exam-report__open" href="<?php echo esc_url( add_query_arg( array( 'row' => (int) $row['n'] ), $page_url ) . '#fs-exam-report-review' ); ?>"><?php esc_html_e( 'Результат и работа', 'fs-lms' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( null !== $review ) : ?>
		<section class="fs-exam-report__review" id="fs-exam-report-review">
			<h2 class="fs-exam-report__review-title"><?php echo esc_html( (string) $review['name'] ); ?></h2>
			<p class="fs-exam-result__score"><?php echo esc_html( (string) $review['caption'] ); ?></p>
			<?php if ( ! empty( $review['preliminary'] ) ) : ?>
				<p class="fs-exam-result__note"><?php esc_html_e( 'Предварительный результат: проверены не все задания.', 'fs-lms' ); ?></p>
			<?php endif; ?>
			<?php
			$units  = $review['units'];
			$counts = $review['counts'];
			$tasks  = $review['tasks'];
			include __DIR__ . '/partials/exam-review.php';
			?>
		</section>
	<?php endif; ?>
</main>
