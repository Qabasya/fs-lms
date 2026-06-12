<?php

declare( strict_types=1 );

use Inc\Enums\EntityType;
use Inc\Services\Log\LogNameResolver;

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

		<input type="number" name="actor_id" placeholder="User ID" value="<?php echo esc_attr( $deletion_filters['actor_user_id'] ?? '' ); ?>" class="input-width-md">

		<select name="entity_type">
			<option value="">Все типы</option>
			<?php foreach ( EntityType::cases() as $et ) : ?>
				<option value="<?php echo esc_attr( $et->value ); ?>" <?php selected( $deletion_filters['entity_type'] ?? '', $et->value ); ?>>
					<?php echo esc_html( $et->label() ); ?>
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

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="deletion"
			data-filters="<?php echo esc_attr( wp_json_encode( $deletion_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
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
                <th class="tw-3">ID</th>
                <th class="tw-10">Дата</th>
                <th class="tw-10">Пользователь</th>
				<th class="tw-10">Тип</th>
				<th class="tw-10">ID сущности</th>
				<th>Каскадно удалено</th>
                <th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $deletion_rows as $row ) : ?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId ); // phpcs:ignore ?></td>
					<td>
						<span class="fs-badge badge-warning">
							<?php echo esc_html( EntityType::tryFrom( $row->entityType )?->label() ?? $row->entityType ); ?>
						</span>
					</td>
					<td><code>#<?php echo (int) $row->entityId; ?></code></td>
					<td><?php echo $row->cascadedSummary ? esc_html( $row->cascadedSummary ) : '—'; ?></td>
					<td><code><?php echo esc_html( $row->actorIp ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $deletion_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
