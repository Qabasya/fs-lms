<?php

declare( strict_types=1 );

use Inc\Enums\Export\ExportActionType;
use Inc\Enums\Export\ExportTarget;
use Inc\Services\Log\LogNameResolver;

/**
 * @var string[] $export_data_types
 * @var array<int, string> $export_actor_options
 */

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\ExportLogDTO[] $export_rows
 * @var int                         $export_total
 * @var int                         $export_page
 * @var array                       $export_filters
 * @var int                         $per_page
 * @var string                      $active_tab
 * @var string              $log_orderby
 * @var string              $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $export_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-3' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $export_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $export_filters, $base_url );

?>

<div class="fs-logs-tab" id="js-export-log-tab">
    <p class="description">
        Каждый экспорт данных фиксируется здесь.
    </p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-3">

		<select name="actor_id">
			<option value="">Все пользователи</option>
			<?php foreach ( $export_actor_options ?? array() as $uid => $name ) : ?>
				<option value="<?php echo esc_attr( (string) $uid ); ?>" <?php selected( $export_filters['actor_user_id'] ?? '', $uid ); ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="data_type">
			<option value="">Все типы</option>
			<?php foreach ( $export_data_types ?? array() as $dtVal ) :
				$dt = ExportTarget::tryFrom( $dtVal );
			?>
				<option value="<?php echo esc_attr( $dtVal ); ?>" <?php selected( $export_filters['data_type'] ?? '', $dtVal ); ?>>
					<?php echo esc_html( $dt ? $dt->label() : $dtVal ); ?>
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

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="export"
			data-filters="<?php echo esc_attr( wp_json_encode( $export_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
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
                <th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
                <th class="tw-7">Дата</th>
                <th class="tw-10">Пользователь</th>
				<th class="tw-20">Тип данных</th>
				<th>Действие</th>
				<th>Цели</th>
                <th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $export_rows as $row ) : ?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( ExportTarget::tryFrom( $row->dataType )?->label() ?? $row->dataType ); ?></td>
					<td><?php echo esc_html( ExportActionType::tryFrom( $row->actionType )?->label() ?? $row->actionType ); ?></td>
					<td><?php echo esc_html( LogNameResolver::exportTargets( $row->dataType, $row->targetIdsJson, 3 ) ); ?></td>
					<td><?php echo esc_html( $row->actorIp ); ?></td>
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
