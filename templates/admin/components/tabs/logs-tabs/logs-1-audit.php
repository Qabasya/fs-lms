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
 * @var string              $active_tab
 * @var string              $log_orderby
 * @var string              $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $audit_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-1' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $audit_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $audit_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-audit-log-tab">

    <p class="description fs-mt-md">
        Каждое действие с учениками фиксируется здесь.
    </p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-1">

		<select name="action_filter">
			<option value="">Все действия</option>
			<?php foreach ( AuditAction::cases() as $action ) : ?>
				<option value="<?php echo esc_attr( $action->value ); ?>"
					<?php selected( $audit_filters['action_filter'] ?? '', $action->value ); ?>>
					<?php echo esc_html( $action->label() ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="text" name="actor_name" placeholder="Имя пользователя"
			value="<?php echo esc_attr( $audit_actor_name ?? '' ); ?>"
			class="input-width-lg">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $audit_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $audit_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $audit_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="enrollment"
			data-filters="<?php echo esc_attr( wp_json_encode( $audit_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
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
				<th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
                <th class="tw-7">Дата</th>
				<th class="tw-10">Пользователь</th>
				<th >Действие</th>
				<th class="tw-20">Субъект</th>
				<th class="tw-20">Группа</th>
				<th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $audit_rows as $row ) :
				$auditAction = AuditAction::tryFrom( $row->action );
				$actionLabel = $auditAction ? $auditAction->label() : $row->action;

				if ( 'application' === $row->targetType ) {
					$subjectCell = 'Заявка №' . (int) $row->targetId;
				} else {
					$subjectCell = esc_html( LogNameResolver::personName( $row->targetId ) );
				}

				$details = $row->detailsJson ? json_decode( $row->detailsJson, true ) : array();
				$groupId = $details['group_id'] ?? null;
				$groupCell = $groupId ? LogNameResolver::entityName( (int) $groupId, 'group' ) : '—';
			?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td><?php echo LogNameResolver::userName( $row->actorUserId); // phpcs:ignore ?></td>
					<td><?php echo esc_html( $actionLabel ); ?>	</td>
					<td><?php echo $subjectCell; // phpcs:ignore ?></td>
					<td><?php echo $groupCell; // phpcs:ignore ?></td>
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
