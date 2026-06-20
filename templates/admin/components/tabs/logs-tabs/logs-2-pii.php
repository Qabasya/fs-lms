<?php

declare( strict_types=1 );

use Inc\Enums\Export\DataFieldType;
use Inc\Enums\Person\PiiAccessReason;
use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Person\PiiAccessLogDTO[] $pii_rows
 * @var int                               $pii_total
 * @var int                               $pii_page
 * @var array                             $pii_filters
 * @var int                               $per_page
 * @var string                            $active_tab
 * @var string              $log_orderby
 * @var string              $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $pii_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-2' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $pii_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $pii_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-pii-log-tab">

	<p class="description fs-mt-md">
		Каждое обращение к персональным данным через функцию «Показать» фиксируется здесь.
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-2">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $pii_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $pii_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $pii_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="pii"
			data-filters="<?php echo esc_attr( wp_json_encode( $pii_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $pii_total ); ?></strong>
		<?php if ( ! empty( $pii_filters ) ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<?php if ( empty( $pii_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
                <th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
                <th class="tw-7">Дата</th>
				<th class="tw-10">Кто смотрел</th>
				<th class="tw-15">Субъект ПД</th>
				<th>Поля</th>
				<th class="tw-20">Причина</th>
				<th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $pii_rows as $row ) :
				$fields = array_map( 'trim', explode( ',', $row->fieldsAccessed ) );
			?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td><?php echo LogNameResolver::userName( $row->actorUserId); // phpcs:ignore ?></td>
					<td><?php echo esc_html( LogNameResolver::personName( $row->personId ) ); ?></td>
					<td>
                        <?php
                        $output = [];
                        foreach ( $fields as $field ) {
                            $output[] = esc_html( DataFieldType::tryFrom( $field )?->label() ?? $field );
                        }
                        echo implode( ', ', $output );
                        ?>
					</td>
					<td><?php echo esc_html( PiiAccessReason::tryFrom( $row->accessReason )?->label() ?? $row->accessReason ); ?></td>
					<td><?php echo esc_html( $row->actorIp ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php echo paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%', $filter_url ),
						'format'    => '',
						'current'   => $pii_page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					) ); // phpcs:ignore ?>
				</div>
			</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
