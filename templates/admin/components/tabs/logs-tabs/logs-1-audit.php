<?php

declare( strict_types=1 );

use Inc\Enums\AuditAction;
use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\AuditLogDTO[] $audit_rows
 * @var int                    $audit_total
 * @var int                    $audit_page
 * @var array                  $audit_filters
 * @var int                    $per_page
 * @var string                 $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $audit_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-1' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $audit_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-audit-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-1">

		<select name="action_filter">
			<option value="">Все действия</option>
			<?php foreach ( AuditAction::cases() as $action ) : ?>
				<option value="<?php echo esc_attr( $action->value ); ?>"
					<?php selected( $audit_filters['action'] ?? '', $action->value ); ?>>
					<?php echo esc_html( $action->label() ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="number" name="actor_id" placeholder="User ID" style="width:90px;"
			value="<?php echo esc_attr( $audit_filters['actor_user_id'] ?? '' ); ?>">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $audit_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $audit_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $audit_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv"
			data-channel="enrollment"
			data-filters="<?php echo esc_attr( wp_json_encode( $audit_filters ) ); ?>"
			style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $audit_total ); ?></strong>
		<?php if ( ! empty( $audit_filters ) ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<?php if ( empty( $audit_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:180px">Пользователь</th>
				<th>Действие</th>
				<th style="width:180px">Субъект</th>
				<th style="width:150px">Группа</th>
				<th style="width:90px">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $audit_rows as $row ) :
				$auditAction = AuditAction::tryFrom( $row->action );
				$actionLabel = $auditAction ? $auditAction->label() : $row->action;

				if ( 'application' === $row->targetType ) {
					$subjectCell = '<span class="fs-badge badge-secondary">Заявка #' . (int) $row->targetId . '</span>';
				} else {
					$subjectCell = esc_html( LogNameResolver::personName( $row->targetId ) );
				}

				$details = $row->detailsJson ? json_decode( $row->detailsJson, true ) : array();
				$groupId = $details['group_id'] ?? null;
				$groupCell = $groupId ? LogNameResolver::entityName( (int) $groupId, 'group' ) : '—';
			?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId, $row->actorRole ); // phpcs:ignore ?></td>
					<td>
						<span class="fs-badge badge-primary"><?php echo esc_html( $actionLabel ); ?></span>
					</td>
					<td><?php echo $subjectCell; // phpcs:ignore ?></td>
					<td><?php echo $groupCell; // phpcs:ignore ?></td>
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
						'current'   => $audit_page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					) ); // phpcs:ignore ?>
				</div>
			</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
