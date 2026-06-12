<?php

declare( strict_types=1 );

use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Person\PiiAccessLogDTO[] $pii_rows
 * @var int                               $pii_total
 * @var int                               $pii_page
 * @var array                             $pii_filters
 * @var int                               $per_page
 * @var string                            $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $pii_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-2' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $pii_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-pii-log-tab">

	<p class="description" style="margin-top:12px;">
		Каждое обращение к персональным данным через функцию «Показать» фиксируется здесь.
		Журнал защищён от изменений.
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-2">

		<input type="number" name="actor_id" placeholder="User ID" style="width:90px;"
			value="<?php echo esc_attr( $pii_filters['actor_user_id'] ?? '' ); ?>">

		<input type="number" name="person_id" placeholder="Person ID" style="width:90px;"
			value="<?php echo esc_attr( $pii_filters['person_id'] ?? '' ); ?>">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $pii_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $pii_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $pii_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv"
			data-channel="pii"
			data-filters="<?php echo esc_attr( wp_json_encode( $pii_filters ) ); ?>"
			style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
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
                <th class="tw-3">ID</th>
                <th class="tw-10">Дата</th>
				<th class="tw-10">Кто смотрел</th>
				<th class="tw-10">Субъект ПД</th>
				<th>Поля</th>
				<th>Причина</th>
				<th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $pii_rows as $row ) :
				$fields = array_map( 'trim', explode( ',', $row->fieldsAccessed ) );
			?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId, $row->actorRole ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( LogNameResolver::personName( $row->personId ) ); ?></td>
					<td>
						<?php foreach ( $fields as $field ) : ?>
							<code style="font-size:11px;"><?php echo esc_html( $field ); ?></code>
						<?php endforeach; ?>
					</td>
					<td><?php echo esc_html( $row->accessReason ); ?></td>
					<td><code><?php echo esc_html( $row->actorIp ); ?></code></td>
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
