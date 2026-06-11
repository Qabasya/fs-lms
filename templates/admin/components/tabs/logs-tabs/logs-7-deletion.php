<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\DeletionLogDTO[] $deletion_rows
 * @var int                           $deletion_total
 * @var int                           $deletion_page
 * @var array                         $deletion_filters
 * @var int                           $per_page
 * @var string                        $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $deletion_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-7' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $deletion_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-deletion-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-7">

		<input type="number" name="actor_id" placeholder="User ID" value="<?php echo esc_attr( $deletion_filters['actor_user_id'] ?? '' ); ?>" style="width:90px;">

		<select name="entity_type">
			<option value="">Все типы</option>
			<?php foreach ( array( 'person', 'group', 'subject', 'period' ) as $et ) : ?>
				<option value="<?php echo esc_attr( $et ); ?>" <?php selected( $deletion_filters['entity_type'] ?? '', $et ); ?>>
					<?php echo esc_html( $et ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $deletion_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $deletion_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $deletion_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv"
			data-channel="deletion"
			data-filters="<?php echo esc_attr( wp_json_encode( $deletion_filters ) ); ?>"
			style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $deletion_total ); ?></strong></p>

	<?php if ( empty( $deletion_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:150px">Пользователь</th>
				<th style="width:90px">Тип</th>
				<th style="width:80px">ID сущности</th>
				<th>Каскадно удалено</th>
				<th style="width:90px">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $deletion_rows as $row ) :
				$actor    = get_userdata( $row->actorUserId );
				$username = $actor ? esc_html( $actor->display_name ) : '#' . $row->actorUserId;
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( wp_date( 'd.m.Y H:i:s', strtotime( $row->createdAt ) ) ); ?></code></td>
					<td><?php echo $username; ?></td>
					<td><code><?php echo esc_html( $row->entityType ); ?></code></td>
					<td><code>#<?php echo (int) $row->entityId; ?></code></td>
					<td><?php echo $row->cascadedSummary ? esc_html( $row->cascadedSummary ) : '—'; ?></td>
					<td><code><?php echo esc_html( $row->actorIp ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $deletion_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
