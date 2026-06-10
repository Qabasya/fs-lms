<?php

declare( strict_types=1 );

use Inc\Enums\AuditAction;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\AuditLogDTO[] $audit_rows
 * @var int                    $audit_total
 * @var int                    $audit_page
 * @var array                  $audit_filters
 * @var AuditAction[]          $audit_actions
 * @var int                    $per_page
 * @var string                 $active_tab
 */

$page_slug    = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages  = (int) ceil( $audit_total / $per_page );
$base_url     = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-1' ), admin_url( 'admin.php' ) );
$filter_url   = add_query_arg( $audit_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-audit-log-tab">

	<!-- Фильтры -->
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-1">

		<select name="action_filter">
			<option value="">Все действия</option>
			<?php foreach ( AuditAction::cases() as $action ) : ?>
				<option value="<?php echo esc_attr( $action->value ); ?>"
					<?php selected( $audit_filters['action'] ?? '', $action->value ); ?>>
					<?php echo esc_html( $action->value ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="number" name="actor_id" placeholder="User ID"
			value="<?php echo esc_attr( $audit_filters['actor_user_id'] ?? '' ); ?>"
			style="width:90px;">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $audit_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $audit_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $audit_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-audit" style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<!-- Итог -->
	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $audit_total ); ?></strong>
		<?php if ( ! empty( $audit_filters ) ) : ?>
			<em>(с фильтрами)</em>
		<?php endif; ?>
	</p>

	<?php if ( empty( $audit_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items">
			<p>Записи не найдены.</p>
		</div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:160px">Пользователь</th>
				<th style="width:90px">Роль</th>
				<th style="width:200px">Действие</th>
				<th style="width:110px">Объект</th>
				<th style="width:90px">IP</th>
				<th>Детали</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $audit_rows as $row ) :
				$actor    = $row->actorUserId ? get_userdata( $row->actorUserId ) : null;
				$username = $actor ? esc_html( $actor->display_name ) : ( $row->actorUserId ? '#' . $row->actorUserId : '—' );
				$details  = $row->detailsJson ? json_decode( $row->detailsJson, true ) : array();
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( wp_date( 'd.m.Y H:i:s', strtotime( $row->createdAt ) ) ); ?></code></td>
					<td><?php echo $username; ?></td>
					<td><?php echo $row->actorRole ? '<code>' . esc_html( $row->actorRole ) . '</code>' : '—'; ?></td>
					<td><code><?php echo esc_html( $row->action ); ?></code></td>
					<td>
						<?php if ( $row->targetType ) : ?>
							<code><?php echo esc_html( $row->targetType ); ?></code>
							<?php if ( $row->targetId ) : ?>
								#<?php echo (int) $row->targetId; ?>
							<?php endif; ?>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $row->actorIp ); ?></code></td>
					<td>
						<?php if ( ! empty( $details ) ) : ?>
							<details>
								<summary style="cursor:pointer; color:#2271b1;">Показать</summary>
								<pre style="font-size:11px; margin:4px 0; max-height:120px; overflow-y:auto;"><?php echo esc_html( json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
							</details>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
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
						'current'   => $audit_page,
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

<script type="application/json" id="js-audit-export-filters">
<?php echo wp_json_encode( array(
	'action_filter' => $audit_filters['action'] ?? '',
	'actor_id'      => $audit_filters['actor_user_id'] ?? '',
	'date_from'     => $audit_filters['date_from'] ?? '',
	'date_to'       => $audit_filters['date_to'] ?? '',
) ); ?>
</script>
