<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\AuthLogDTO[] $auth_rows
 * @var int                       $auth_total
 * @var int                       $auth_page
 * @var array                     $auth_filters
 * @var int                       $per_page
 * @var string                    $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $auth_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-8' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $auth_filters, $base_url );

$actions = array( 'login', 'login_failed', 'otp_sent', 'otp_verified', 'password_reset' );
?>

<div class="fs-logs-tab" id="js-auth-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-8">

		<select name="action_filter">
			<option value="">Все действия</option>
			<?php foreach ( $actions as $a ) : ?>
				<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $auth_filters['action'] ?? '', $a ); ?>>
					<?php echo esc_html( $a ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="result">
			<option value="">Все результаты</option>
			<option value="success" <?php selected( $auth_filters['result'] ?? '', 'success' ); ?>>Успех</option>
			<option value="failure" <?php selected( $auth_filters['result'] ?? '', 'failure' ); ?>>Неудача</option>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $auth_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $auth_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $auth_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv" data-channel="auth" style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $auth_total ); ?></strong></p>

	<?php if ( empty( $auth_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:180px">Логин</th>
				<th style="width:130px">Действие</th>
				<th style="width:80px">Результат</th>
				<th style="width:100px">IP</th>
				<th>Устройство</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $auth_rows as $row ) :
				$badge = 'success' === $row->result
					? '<span class="fs-badge fs-badge--green">Успех</span>'
					: '<span class="fs-badge fs-badge--red">Неудача</span>';
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( wp_date( 'd.m.Y H:i:s', strtotime( $row->createdAt ) ) ); ?></code></td>
					<td><?php echo $row->loginIdentifier ? esc_html( $row->loginIdentifier ) : '—'; ?></td>
					<td><code><?php echo esc_html( $row->action ); ?></code></td>
					<td><?php echo $badge; ?></td>
					<td><code><?php echo esc_html( $row->actorIp ); ?></code></td>
					<td>
						<?php if ( $row->actorUa ) : ?>
							<span title="<?php echo esc_attr( $row->actorUa ); ?>" style="cursor:help;">
								<?php echo esc_html( mb_substr( $row->actorUa, 0, 40 ) ) . ( mb_strlen( $row->actorUa ) > 40 ? '…' : '' ); ?>
							</span>
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
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $auth_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
