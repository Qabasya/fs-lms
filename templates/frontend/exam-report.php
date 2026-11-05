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
 * идентификаторов попыток и участий или кнопок оценивания: строка раскрывается по порядковому номеру в отчёте (`?row=`; `p` занят WordPress — это ID записи).
 *
 * @package FS LMS
 *
 * @var string $title
 * @var string $event
 * @var string $page_url
 * @var list<array{n: int, name: string, short_name: string, primary: string, secondary: string, units: list<array{number: string, status: string}>, open: bool}> $rows
 * @var array{columns: list<string>, participants: int, rows: list<array<string, mixed>>, footer: array<string, mixed>} $table Матрица «ученики × задания» (`ExamReportViewService::matrix()`)
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
		<h1 class="fs-exam-result__title">
			<?php echo esc_html( $title ); ?>
		</h1>
		<div class="fs-exam-report__meta-row"><p class="fs-exam-result__meta"><?php echo esc_html( $event ); ?></p><a class="prof-btn prof-btn-sm fs-exam-report__csv" href="<?php echo esc_url( add_query_arg( array( 'csv' => '1' ), $page_url ) ); ?>"><?php esc_html_e( 'Скачать отчёт', 'fs-lms' ); ?></a></div>
	</header>

	<p class="fs-exam-result__meta fs-exam-report__hint"><?php esc_html_e( 'Нажмите на строку ученика, чтобы открыть его работу ниже.', 'fs-lms' ); ?></p>

	<dl class="fs-exam-result__stats">
		<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Учеников', 'fs-lms' ); ?></dt><dd><?php echo (int) $stats['participants']; ?></dd></div>
		<div class="fs-exam-result__stat"><dt><?php esc_html_e( 'Средний балл', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['average'] ); ?></dd></div>
		<div class="fs-exam-result__stat is-correct"><dt><?php esc_html_e( 'Лучший результат', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['best'] ); ?></dd></div>
		<div class="fs-exam-result__stat is-wrong"><dt><?php esc_html_e( 'Худший результат', 'fs-lms' ); ?></dt><dd><?php echo esc_html( (string) $stats['worst'] ); ?></dd></div>
	</dl>

	<?php
	$columns = $table['columns'] ?? array();
	$rows    = $table['rows'] ?? array();
	$footer  = $table['footer'] ?? array( 'average_primary' => '', 'average_secondary' => '', 'attempts' => array(), 'correct' => array(), 'attempts_share' => array(), 'correct_share' => array() );
	?>

	<?php if ( array() === $rows ) : ?>
		<p class="description">Участников в отчёте нет.</p>
	<?php else : ?>
		<div class="fs-exam-report__scroll">
			<table class="fs-exam-report__table">
				<thead>
					<tr>
						<th class="fs-exam-report__name"><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></th>
						<?php foreach ( $columns as $number ) : ?>
							<th class="fs-exam-report__task"><?php echo esc_html( $number ); ?></th>
						<?php endforeach; ?>
						<th class="fs-exam-report__sum"><?php esc_html_e( 'Балл', 'fs-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php $current = null !== $review && (int) $review['row'] === (int) $row['n']; ?>
						<tr class="<?php echo $row['open'] ? 'has-units' : 'is-placeholder'; ?><?php echo $current ? ' is-current' : ''; ?>"
							<?php if ( $row['open'] ) : ?>
								data-row-url="<?php echo esc_url( add_query_arg( array( 'row' => (int) $row['n'] ), $page_url ) . '#fs-exam-report-review' ); ?>"
							<?php endif; ?>>
							<th class="fs-exam-report__name" scope="row"><?php if ( $row['open'] ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'row' => (int) $row['n'] ), $page_url ) . '#fs-exam-report-review' ); ?>"><?php echo esc_html( $row['short_name'] ); ?></a><?php else : ?><?php echo esc_html( $row['short_name'] ); ?><?php endif; ?></th>
							<?php foreach ( $columns as $number ) : ?>
								<?php $cell = $row['cells'][ $number ] ?? null; ?>
								<td class="fs-exam-report__cell<?php echo null !== $cell && '' !== $cell['class'] ? ' ' . esc_attr( (string) $cell['class'] ) : ''; ?>">
									<?php echo null !== $cell ? esc_html( (string) $cell['score'] ) : ''; ?>
								</td>
							<?php endforeach; ?>
							<td class="fs-exam-report__sum"><?php if ( $row['open'] ) : ?><?php echo esc_html( (string) $row['primary'] . ' / ' . ( '' !== $row['secondary'] ? (string) $row['secondary'] : ( $row['grade'] ?? '' ?: '—' ) ) ); ?><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="fs-exam-report__foot">
						<th class="fs-exam-report__name" scope="row"><?php esc_html_e( 'Средний', 'fs-lms' ); ?></th>
						<?php foreach ( $columns as $number ) : ?>
							<td class="fs-exam-report__cell"></td>
						<?php endforeach; ?>
						<td class="fs-exam-report__sum"><?php echo esc_html( ( '' !== $footer['average_primary'] ? $footer['average_primary'] : '—' ) . ' / ' . ( '' !== $footer['average_secondary'] ? $footer['average_secondary'] : '—' ) ); ?></td>
					</tr>
					<tr class="fs-exam-report__foot">
						<th class="fs-exam-report__name" scope="row"><?php esc_html_e( 'Попытки', 'fs-lms' ); ?></th>
						<?php foreach ( $footer['attempts'] as $value ) : ?>
							<td class="fs-exam-report__cell"><?php echo esc_html( (string) $value ); ?></td>
						<?php endforeach; ?>
						<td class="fs-exam-report__sum"></td>
					</tr>
					<tr class="fs-exam-report__foot">
						<th class="fs-exam-report__name" scope="row"><?php esc_html_e( 'Верные решения', 'fs-lms' ); ?></th>
						<?php foreach ( $footer['correct'] as $value ) : ?>
							<td class="fs-exam-report__cell"><?php echo esc_html( (string) $value ); ?></td>
						<?php endforeach; ?>
						<td class="fs-exam-report__sum"></td>
					</tr>
					<tr class="fs-exam-report__foot">
						<th class="fs-exam-report__name" scope="row"><?php esc_html_e( '% попыток', 'fs-lms' ); ?></th>
						<?php foreach ( $footer['attempts_share'] as $value ) : ?>
							<td class="fs-exam-report__cell is-share"><?php echo esc_html( (string) $value ); ?></td>
						<?php endforeach; ?>
						<td class="fs-exam-report__sum"></td>
					</tr>
					<tr class="fs-exam-report__foot">
						<th class="fs-exam-report__name" scope="row"><?php esc_html_e( '% верных', 'fs-lms' ); ?></th>
						<?php foreach ( $footer['correct_share'] as $value ) : ?>
							<td class="fs-exam-report__cell is-share"><?php echo esc_html( (string) $value ); ?></td>
						<?php endforeach; ?>
						<td class="fs-exam-report__sum"></td>
					</tr>
				</tfoot>
			</table>
		</div>
	<?php endif; ?>

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
