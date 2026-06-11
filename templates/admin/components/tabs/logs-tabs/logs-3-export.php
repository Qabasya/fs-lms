<?php

declare( strict_types=1 );

use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\ExportLogDTO[] $export_rows
 * @var int                         $export_total
 * @var int                         $export_page
 * @var array                       $export_filters
 * @var int                         $per_page
 * @var string                      $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $export_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-3' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $export_filters, $base_url );

$data_type_labels = array(
	'groups'   => 'Группы',
	'students' => 'Ученики',
	'parents'  => 'Родители',
	'archive'  => 'Архив',
	'log'      => 'Журнал',
);
?>

<div class="fs-logs-tab" id="js-export-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-3">

		<input type="number" name="actor_id" placeholder="User ID"
			value="<?php echo esc_attr( $export_filters['actor_user_id'] ?? '' ); ?>"
			style="width:90px;">

		<select name="data_type">
			<option value="">Все типы</option>
			<?php foreach ( $data_type_labels as $dt => $label ) : ?>
				<option value="<?php echo esc_attr( $dt ); ?>" <?php selected( $export_filters['data_type'] ?? '', $dt ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $export_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $export_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $export_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv"
			data-channel="export"
			data-filters="<?php echo esc_attr( wp_json_encode( $export_filters ) ); ?>"
			style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $export_total ); ?></strong></p>

	<?php if ( empty( $export_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:180px">Пользователь</th>
				<th style="width:130px">Тип данных</th>
				<th style="width:100px">Действие</th>
				<th>ID целей</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $export_rows as $row ) :
				$ids = $row->targetIdsJson ? json_decode( $row->targetIdsJson, true ) : array();
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId ); // phpcs:ignore ?></td>
					<td>
						<span class="fs-badge badge-secondary">
							<?php echo esc_html( $data_type_labels[ $row->dataType ] ?? $row->dataType ); ?>
						</span>
					</td>
					<td><span class="fs-badge badge-primary"><?php echo esc_html( $row->actionType ); ?></span></td>
					<td>
						<?php if ( ! empty( $ids ) ) : ?>
							<code style="font-size:11px;"><?php echo esc_html( implode( ', ', array_slice( $ids, 0, 10 ) ) ); ?><?php echo count( $ids ) > 10 ? ' …' : ''; ?></code>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $export_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
