<?php

declare( strict_types=1 );

use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\DataChangeLogDTO[] $data_change_rows
 * @var int                             $data_change_total
 * @var int                             $data_change_page
 * @var array                           $data_change_filters
 * @var int                             $per_page
 * @var string                          $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $data_change_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-4' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $data_change_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-data-change-log-tab">

	<p class="description" style="margin-top:12px;">
		Значения хранятся в зашифрованном виде. Полные данные доступны через CSV-экспорт.
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-4">

		<input type="number" name="actor_id"  placeholder="User ID"   value="<?php echo esc_attr( $data_change_filters['actor_user_id'] ?? '' ); ?>" style="width:90px;">
		<input type="number" name="person_id" placeholder="Person ID" value="<?php echo esc_attr( $data_change_filters['target_person_id'] ?? '' ); ?>" style="width:90px;">
		<input type="date" name="date_from" value="<?php echo esc_attr( $data_change_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $data_change_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $data_change_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv"
			data-channel="data_change"
			data-filters="<?php echo esc_attr( wp_json_encode( $data_change_filters ) ); ?>"
			style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $data_change_total ); ?></strong></p>

	<?php if ( empty( $data_change_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:180px">Администратор</th>
				<th style="width:180px">Субъект ПД</th>
				<th style="width:130px">Поле</th>
				<th>Старое значение</th>
				<th>Новое значение</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $data_change_rows as $row ) : ?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( LogNameResolver::personName( $row->targetPersonId ) ); ?></td>
					<td><code><?php echo esc_html( $row->fieldName ); ?></code></td>
					<td><?php echo $row->oldValueEnc ? '<span title="Зашифровано">••••••</span>' : '—'; ?></td>
					<td><?php echo $row->newValueEnc ? '<span title="Зашифровано">••••••</span>' : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $data_change_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
