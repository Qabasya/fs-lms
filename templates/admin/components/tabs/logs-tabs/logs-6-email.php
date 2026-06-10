<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\EmailLogDTO[] $email_rows
 * @var int                        $email_total
 * @var int                        $email_page
 * @var array                      $email_filters
 * @var int                        $per_page
 * @var string                     $active_tab
 */

use Inc\Enums\EmailTemplateType;

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$total_pages = (int) ceil( $email_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-6' ), admin_url( 'admin.php' ) );
$filter_url  = add_query_arg( $email_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-email-log-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-6">

		<select name="email_type">
			<option value="">Все типы</option>
			<?php foreach ( EmailTemplateType::cases() as $type ) : ?>
				<option value="<?php echo esc_attr( $type->value ); ?>" <?php selected( $email_filters['email_type'] ?? '', $type->value ); ?>>
					<?php echo esc_html( $type->value ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value="">Все статусы</option>
			<option value="success" <?php selected( $email_filters['status'] ?? '', 'success' ); ?>>Успешно</option>
			<option value="failed"  <?php selected( $email_filters['status'] ?? '', 'failed' ); ?>>Ошибка</option>
		</select>

		<input type="number" name="person_id" placeholder="Person ID" value="<?php echo esc_attr( $email_filters['target_person_id'] ?? '' ); ?>" style="width:90px;">
		<input type="date" name="date_from" value="<?php echo esc_attr( $email_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $email_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $email_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv" data-channel="email" style="margin-left:auto;">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $email_total ); ?></strong></p>

	<?php if ( empty( $email_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th style="width:50px">ID</th>
				<th style="width:130px">Дата</th>
				<th style="width:150px">Актор</th>
				<th style="width:160px">Тип письма</th>
				<th style="width:90px">Person ID</th>
				<th style="width:80px">Статус</th>
				<th>Ошибка</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $email_rows as $row ) :
				$actor    = $row->actorUserId ? get_userdata( $row->actorUserId ) : null;
				$username = $actor ? esc_html( $actor->display_name ) : ( $row->actorUserId ? '#' . $row->actorUserId : '—' );
				$badge    = 'success' === $row->status
					? '<span class="fs-badge fs-badge--green">Успешно</span>'
					: '<span class="fs-badge fs-badge--red">Ошибка</span>';
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><code><?php echo esc_html( wp_date( 'd.m.Y H:i:s', strtotime( $row->createdAt ) ) ); ?></code></td>
					<td><?php echo $username; ?></td>
					<td><code><?php echo esc_html( $row->emailType ); ?></code></td>
					<td><?php echo $row->targetPersonId ? '<code>#' . (int) $row->targetPersonId . '</code>' : '—'; ?></td>
					<td><?php echo $badge; ?></td>
					<td><?php echo $row->errorMessage ? esc_html( $row->errorMessage ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $email_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
