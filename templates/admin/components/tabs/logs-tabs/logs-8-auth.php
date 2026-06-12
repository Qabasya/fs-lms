<?php

declare( strict_types=1 );

use Inc\Enums\AuthAction;
use Inc\Enums\AuthResult;
use Inc\Services\Log\LogNameResolver;

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

?>

<div class="fs-logs-tab" id="js-auth-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-8">

		<select name="action_filter">
			<option value="">Все действия</option>
			<?php foreach ( AuthAction::cases() as $action ) : ?>
				<option value="<?php echo esc_attr( $action->value ); ?>" <?php selected( $auth_filters['action'] ?? '', $action->value ); ?>>
					<?php echo esc_html( $action->label() ); ?>
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

		<button type="button" class="button js-export-log-csv"
			data-channel="auth"
			data-filters="<?php echo esc_attr( wp_json_encode( $auth_filters ) ); ?>"
			style="margin-left:auto;">
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
                <th class="tw-3">ID</th>
                <th class="tw-10">Дата</th>
				<th class="tw-15">Логин</th>
				<th>Действие</th>
				<th>Результат</th>
                <th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $auth_rows as $row ) :
				$authResult = AuthResult::tryFrom( $row->result );
				$badge      = $authResult
					? '<span class="fs-badge ' . esc_attr( $authResult->badgeClass() ) . '">' . esc_html( $authResult->label() ) . '</span>'
					: '<span class="fs-badge">' . esc_html( $row->result ) . '</span>';
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo $row->loginIdentifier ? esc_html( $row->loginIdentifier ) : '—'; ?></td>
					<td>
						<span class="fs-badge badge-secondary">
							<?php echo esc_html( AuthAction::tryFrom( $row->action )?->label() ?? $row->action ); ?>
						</span>
					</td>
					<td><?php echo $badge; ?></td>
					<td><code><?php echo esc_html( $row->actorIp ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $auth_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
