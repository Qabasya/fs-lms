<?php

declare( strict_types=1 );

use Inc\DTO\Log\ContentReportLogDTO;
use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Вкладка «Найденные ошибки»: сообщения читателей кнопки «Нашли ошибку?»
 * на страницах заданий и статей. Текст открывается в модалке.
 *
 * @var ContentReportLogDTO[] $report_rows
 * @var int                   $report_total
 * @var int                   $report_page
 * @var array                 $report_filters
 * @var int                   $per_page
 * @var string                $log_orderby
 * @var string                $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $report_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-10' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $report_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $report_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-content-reports-tab">
	<p class="description">
		Сообщения читателей, нашедших ошибку в задании или статье. Текст открывается кнопкой «Показать текст».
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-10">

		<input type="date" name="date_from" value="<?php echo esc_attr( $report_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $report_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $report_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $report_total ); ?></strong></p>

	<?php if ( empty( $report_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th class="tw-7"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
				<th class="tw-15">Дата и время</th>
				<th>Страница</th>
				<th class="tw-15">Текст</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $report_rows as $row ) :
				$title = $row->postId > 0 ? get_the_title( $row->postId ) : '';
				?>
				<tr>
					<td><code>#<?php echo esc_html( (string) $row->id ); ?></code></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td>
						<?php if ( ! empty( $row->url ) ) : ?>
							<a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( '' !== $title ? $title : $row->url ); ?>
							</a>
						<?php else : ?>
							<?php echo esc_html( $title ); ?>
						<?php endif; ?>
					</td>
					<td>
						<button type="button" class="button js-report-text-open"
							data-id="<?php echo esc_attr( (string) $row->id ); ?>"
							data-text="<?php echo esc_attr( $row->message ); ?>">Показать текст</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $report_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>

	<div id="fs-lms-report-text-modal" class="fs-lms-modal hidden">
		<div class="fs-lms-modal-backdrop"></div>
		<div class="fs-lms-modal-content">
			<div class="fs-lms-modal-header">
				<h2 class="fs-lms-modal-title">Сообщение об ошибке</h2>
				<button type="button" class="fs-lms-modal-close" aria-label="Закрыть">&times;</button>
			</div>
			<div class="fs-lms-modal-body">
				<p class="fs-report-text js-report-text"></p>
			</div>
		</div>
	</div>
</div>
