<?php

declare( strict_types=1 );

use Inc\DTO\Log\EntityAuditLogDTO;
use Inc\Enums\EntityType;
use Inc\Enums\OperationType;
use Inc\Services\Log\LogNameResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var EntityAuditLogDTO[] $entity_audit_rows
 * @var int                 $entity_audit_total
 * @var int                 $entity_audit_page
 * @var array               $entity_audit_filters
 * @var int                 $per_page
 * @var string              $active_tab
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $entity_audit_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-0' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $entity_audit_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-entity-audit-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-0">

		<select name="operation">
			<option value="">Все операции</option>
			<?php foreach ( OperationType::cases() as $op ) : ?>
				<option value="<?php echo esc_attr( $op->value ); ?>"
					<?php selected( $entity_audit_filters['operation'] ?? '', $op->value ); ?>>
					<?php echo esc_html( $op->label() ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="entity_type">
			<option value="">Все типы</option>
			<?php foreach ( EntityType::cases() as $et ) : ?>
				<option value="<?php echo esc_attr( $et->value ); ?>"
					<?php selected( $entity_audit_filters['entity_type'] ?? '', $et->value ); ?>>
					<?php echo esc_html( $et->label() ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="number" name="actor_id" placeholder="User ID" class="input-width-md"
			value="<?php echo esc_attr( $entity_audit_filters['actor_user_id'] ?? '' ); ?>">

		<input type="date" name="date_from"
			value="<?php echo esc_attr( $entity_audit_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"
			value="<?php echo esc_attr( $entity_audit_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $entity_audit_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="entity_audit"
			data-filters="<?php echo esc_attr( wp_json_encode( $entity_audit_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $entity_audit_total ); ?></strong>
		<?php if ( ! empty( $entity_audit_filters ) ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<?php if ( empty( $entity_audit_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items">
			<p>Записи не найдены.</p>
		</div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
                <th class="tw-5">ID</th>
                <th class="tw-10">Дата</th>
                <th class="tw-10">Пользователь</th>
				<th >Операция</th>
				<th class="tw-10">Тип сущности</th>
				<th class="tw-10">Сущность</th>
				<th class="tw-10">Прошлое название</th>
				<th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $entity_audit_rows as $row ) :
				$op = OperationType::tryFrom( $row->operation );
				$et = EntityType::tryFrom( $row->entityType );
			?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></code></td>
					<td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId, $row->actorRole ); // phpcs:ignore ?></td>
					<td>
						<?php if ( $op ) : ?>
							<span class="fs-badge <?php echo esc_attr( $op->badgeClass() ); ?>">
								<?php echo esc_html( $op->label() ); ?>
							</span>
						<?php else : ?>
							<code><?php echo esc_html( $row->operation ); ?></code>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $et ) : ?>
							<span class="fs-badge <?php echo esc_attr( $et->badgeClass() ); ?>">
								<?php echo esc_html( $et->label() ); ?>
							</span>
						<?php else : ?>
							<code><?php echo esc_html( $row->entityType ); ?></code>
						<?php endif; ?>
					</td>
					<td>
						<?php echo LogNameResolver::entityName( $row->entityId, $row->entityType, $row->oldLabel ); // phpcs:ignore ?>
						<?php if ( $row->entityId ) : ?>
							<span class="fs-text-muted fs-code-sm">#<?php echo (int) $row->entityId; ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo $row->oldLabel ? esc_html( $row->oldLabel ) : '—'; ?>
					</td>
					<td><code><?php echo esc_html( $row->actorIp ?? '—' ); ?></code></td>
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
						'current'   => $entity_audit_page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					) ); // phpcs:ignore ?>
				</div>
			</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
