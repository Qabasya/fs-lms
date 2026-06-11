<?php

declare( strict_types=1 );

use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\ConsentChangeLogDTO[] $consent_rows
 * @var int                                $consent_total
 * @var int                                $consent_page
 * @var array                              $consent_filters
 * @var int                                $per_page
 * @var string                             $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $consent_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-5' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $consent_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-consent-change-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-5">

		<input type="number" name="person_id" placeholder="Person ID" value="<?php echo esc_attr( $consent_filters['person_id'] ?? '' ); ?>" style="width:90px;">
		<input type="text"   name="consent_type" placeholder="Тип согласия" value="<?php echo esc_attr( $consent_filters['consent_type'] ?? '' ); ?>" style="width:130px;">
		<input type="date" name="date_from" value="<?php echo esc_attr( $consent_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $consent_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $consent_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv"
			data-channel="consent_change"
			data-filters="<?php echo esc_attr( wp_json_encode( $consent_filters ) ); ?>"
			style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $consent_total ); ?></strong></p>

	<?php if ( empty( $consent_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:180px">Актор</th>
				<th style="width:180px">Субъект ПД</th>
				<th style="width:150px">Тип согласия</th>
				<th>Старый хеш</th>
				<th>Новый хеш</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $consent_rows as $row ) : ?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( LogNameResolver::personName( $row->personId ) ); ?></td>
					<td><code><?php echo esc_html( $row->consentType ); ?></code></td>
					<td><?php echo $row->oldHash ? '<code style="font-size:10px;">' . esc_html( substr( $row->oldHash, 0, 16 ) ) . '…</code>' : '—'; ?></td>
					<td><?php echo $row->newHash ? '<code style="font-size:10px;">' . esc_html( substr( $row->newHash, 0, 16 ) ) . '…</code>' : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $consent_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
