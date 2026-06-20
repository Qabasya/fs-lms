<?php

declare( strict_types=1 );

use Inc\DTO\Log\EntityAuditLogDTO;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\OperationType;
use Inc\Services\Log\LogNameResolver;

/**
 * @var string[] $entity_audit_operations
 * @var string[] $entity_audit_types
 * @var array<int, string> $entity_audit_actor_options
 */

defined( 'ABSPATH' ) || exit;

/**
 * @var EntityAuditLogDTO[] $entity_audit_rows
 * @var int                 $entity_audit_total
 * @var int                 $entity_audit_page
 * @var array               $entity_audit_filters
 * @var int                 $per_page
 * @var string              $active_tab
 * @var string              $log_orderby
 * @var string              $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $entity_audit_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-0' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $entity_audit_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $entity_audit_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-entity-audit-tab">
    <p class="description fs-mt-md">
        Каждое действие с сущностью (предмет, таксономия, пользователи и т.д.) фиксируется здесь.
    </p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-0">

		<select name="actor_id">
			<option value="">Все пользователи</option>
			<?php foreach ( $entity_audit_actor_options ?? array() as $uid => $name ) : ?>
				<option value="<?php echo esc_attr( (string) $uid ); ?>" <?php selected( $entity_audit_filters['actor_user_id'] ?? '', $uid ); ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="operation">
			<option value="">Все операции</option>
			<?php foreach ( $entity_audit_operations ?? array() as $opVal ) :
				$op = OperationType::tryFrom( $opVal );
			?>
				<option value="<?php echo esc_attr( $opVal ); ?>"
					<?php selected( $entity_audit_filters['operation'] ?? '', $opVal ); ?>>
					<?php echo esc_html( $op ? $op->label() : $opVal ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="entity_type">
			<option value="">Все типы</option>
			<?php foreach ( $entity_audit_types ?? array() as $etVal ) :
				$et = EntityType::tryFrom( $etVal );
			?>
				<option value="<?php echo esc_attr( $etVal ); ?>"
					<?php selected( $entity_audit_filters['entity_type'] ?? '', $etVal ); ?>>
					<?php echo esc_html( $et ? $et->label() : $etVal ); ?>
				</option>
			<?php endforeach; ?>
		</select>

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
                <th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
                <th class="tw-7">Дата</th>
                <th class="tw-10">Пользователь</th>
				<th class="tw-10">Операция</th>
				<th class="tw-10">Тип сущности</th>
				<th>Сущность</th>
				<th>Прошлое значение</th>
				<th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $entity_audit_rows as $row ) :
				$op = $row->operation;
				$et = $row->entityType;
			?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td><?php echo LogNameResolver::userName( $row->actorUserId ); // phpcs:ignore ?></td>
					<td>
						<?php if ( $op ) : ?>

								<?php echo esc_html( $op->label() ); ?>

						<?php else : ?>
							<?php echo esc_html( $op->value ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $et ) : ?>

								<?php echo esc_html( $et->label() ); ?>

						<?php else : ?>
							<?php echo esc_html( $et->value ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php echo LogNameResolver::entityName( $row->entityId, $et->value, $row->oldLabel ); // phpcs:ignore ?>
						<?php if ( $row->entityId ) : ?>
							<span class="fs-text-muted fs-code-sm">#<?php echo (int) $row->entityId; ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo ( OperationType::Update === $op ) ? esc_html( $row->oldLabel ?? '' ) : ''; ?>
					</td>
					<td><?php echo esc_html( $row->actorIp ?? '—' ); ?></td>
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
