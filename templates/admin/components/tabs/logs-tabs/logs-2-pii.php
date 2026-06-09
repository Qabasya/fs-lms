<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Person\PiiAccessLogDTO[] $pii_rows
 * @var int                        $pii_total
 * @var int                        $pii_page
 * @var array                      $pii_filters
 * @var int                        $per_page
 * @var string                     $active_tab
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

	<!-- Фильтры -->
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-2">

		<input type="number" name="actor_id" placeholder="User ID"
			value="<?php echo esc_attr( $pii_filters['actor_user_id'] ?? '' ); ?>"
			style="width:90px;">

		<input type="number" name="person_id" placeholder="Person ID"
			value="<?php echo esc_attr( $pii_filters['person_id'] ?? '' ); ?>"
			style="width:90px;">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $pii_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $pii_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $pii_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-pii" style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<!-- Итог -->
	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $pii_total ); ?></strong>
		<?php if ( ! empty( $pii_filters ) ) : ?>
			<em>(с фильтрами)</em>
		<?php endif; ?>
	</p>

	<?php if ( empty( $pii_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items">
			<p>Записи не найдены.</p>
		</div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:160px">Кто смотрел</th>
				<th style="width:90px">Роль</th>
				<th style="width:80px">Person ID</th>
				<th style="width:180px">Поля</th>
				<th>Причина</th>
				<th style="width:90px">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $pii_rows as $row ) :
				$actor    = get_userdata( $row->actorUserId );
				$username = $actor ? esc_html( $actor->display_name ) : '#' . $row->actorUserId;
				$fields   = array_map( 'trim', explode( ',', $row->fieldsAccessed ) );
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( wp_date( 'd.m.Y H:i:s', strtotime( $row->createdAt ) ) ); ?></code></td>
					<td><?php echo $username; ?></td>
					<td><?php echo $row->actorRole ? '<code>' . esc_html( $row->actorRole ) . '</code>' : '—'; ?></td>
					<td><code>#<?php echo (int) $row->personId; ?></code></td>
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

		<!-- Пагинация -->
		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%', $filter_url ),
						'format'    => '',
						'current'   => $pii_page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					) );
					?>
				</div>
			</div>
		<?php endif; ?>

	<?php endif; ?>

</div>

<script type="application/json" id="js-pii-export-filters">
<?php echo wp_json_encode( array(
	'actor_id'  => $pii_filters['actor_user_id'] ?? '',
	'person_id' => $pii_filters['person_id'] ?? '',
	'date_from' => $pii_filters['date_from'] ?? '',
	'date_to'   => $pii_filters['date_to'] ?? '',
) ); ?>
</script>
