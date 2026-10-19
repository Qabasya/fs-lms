<?php
/**
 * Школьный отчёт (этап 12.3): таблица результатов выбранных участников и разбор одного из них под таблицей. Только чтение.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

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
 * @var list<array{n: int, name: string, primary: string, secondary: string, units: list<array{number: string, status: string}>, open: bool}> $rows
 * @var array{participants: int, average: string, best: string, worst: string} $stats
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
		<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Учеников', 'fs-lms' ); ?></dt><dd><?php echo (int) $stats['participants']; ?></dd></div>
		<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Средний балл', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['average'] ); ?></dd></div>
		<div class="fs-exam-result__stat is-correct"><dt><?php esc_html_e( 'Лучший результат', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['best'] ); ?></dd></div>
		<div class="fs-exam-result__stat is-wrong"><dt><?php esc_html_e( 'Худший результат', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['worst'] ); ?></dd></div>
	</dl>

	<table class="fs-exam-report__table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Ученик', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'Первичный балл', 'fs-lms' ); ?></th>
				<th><?php esc_html_e( 'Вторичный балл', 'fs-lms' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $current = null !== $review && (int) $review['row'] === (int) $row['n']; ?>
				<tr class="<?php echo $row['open'] ? 'has-units' : 'is-placeholder'; ?><?php echo $current ? ' is-current' : ''; ?>">
					<td><?php echo esc_html( $row['name'] ); ?></td>
					<td><?php echo esc_html( $row['primary'] ); ?></td>
					<td><?php echo esc_html( $row['secondary'] ); ?></td>
					<td>
						<?php if ( $row['open'] ) : ?>
							<a class="fs-exam-report__open" href="<?php echo esc_url( add_query_arg( array( 'row' => (int) $row['n'] ), $page_url ) . '#fs-exam-report-review' ); ?>"><?php esc_html_e( 'Результат и работа', 'fs-lms' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $row['open'] && array() !== $row['units'] ) : ?>
					<tr class="fs-exam-report__units<?php echo $current ? ' is-current' : ''; ?>">
						<td colspan="4">
							<?php // Те же квадратики, что в навигации разбора, но без ссылок: подпись — только цветом границы и цифры. ?>
							<div class="fs-exam-result__nav fs-exam-report__chips">
								<?php foreach ( $row['units'] as $unit ) : ?>
									<span class="fs-exam-result__chip is-<?php echo esc_attr( (string) $unit['status'] ); ?>"><?php echo esc_html( (string) $unit['number'] ); ?></span>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
				<?php endif; ?>
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
			// Отчёт читает школа: ответ принадлежит ученику, а не тому, кто смотрит.
			$answer_label = __( 'Ответ ученика:', 'fs-lms' );
			include __DIR__ . '/partials/exam-review.php';
			?>
		</section>
	<?php endif; ?>
	<?php // Кнопка «наверх» — показывает и прячет components/scroll-top.js. ?>
	<button type="button" class="fs-to-top js-to-top" aria-label="<?php esc_attr_e( 'Наверх', 'fs-lms' ); ?>" hidden>
		<?php echo \Inc\Enums\Ui\Icon::ChevronDown->svg( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</button>
</main>
