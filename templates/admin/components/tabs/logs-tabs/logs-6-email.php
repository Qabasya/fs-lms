<?php

declare( strict_types=1 );

use Inc\Enums\Email\EmailTemplateType;
use Inc\Services\Log\LogNameResolver;
require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Log\EmailLogDTO[] $email_rows
 * @var int                        $email_total
 * @var int                        $email_page
 * @var array                      $email_filters
 * @var int                        $per_page
 * @var string                     $active_tab
 * @var string                     $log_orderby
 * @var string                     $log_order
 * @var string[]                   $email_type_options
 * @var array<int, string>         $email_person_options
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $email_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-6' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $email_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $email_filters, $base_url );
?>

<div class="fs-logs-tab" id="js-email-log-tab">
    <p class="description fs-mt-md">
        Каждая отправка письма фиксируется здесь.
    </p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-6">

		<select name="email_type">
			<option value="">Все типы</option>
			<?php foreach ( $email_type_options ?? array() as $etVal ) :
				$et = EmailTemplateType::tryFrom( $etVal );
			?>
				<option value="<?php echo esc_attr( $etVal ); ?>" <?php selected( $email_filters['email_type'] ?? '', $etVal ); ?>>
					<?php echo esc_html( $et ? $et->label() : $etVal ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value="">Все статусы</option>
			<option value="success" <?php selected( $email_filters['status'] ?? '', 'success' ); ?>>Успешно</option>
			<option value="failed"  <?php selected( $email_filters['status'] ?? '', 'failed' ); ?>>Ошибка</option>
		</select>

		<select name="person_id">
			<option value="">Все субъекты ПД</option>
			<?php foreach ( $email_person_options ?? array() as $pid => $name ) : ?>
				<option value="<?php echo esc_attr( (string) $pid ); ?>" <?php selected( $email_filters['target_person_id'] ?? '', $pid ); ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<input type="date" name="date_from" value="<?php echo esc_attr( $email_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $email_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $email_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="email"
			data-filters="<?php echo esc_attr( wp_json_encode( $email_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
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
                <th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
                <th class="tw-7">Дата</th>
                <th class="tw-10">Пользователь</th>
				<th >Тип письма</th>
				<th>Субъект ПД</th>
				<th class="tw-10">Email получателя</th>
				<th class="tw-10">Статус</th>
			</tr>
			</thead>
			<tbody>
            <?php foreach ( $email_rows as $row ) :
                $badge_color = 'success' === $row->status
                        ? 'green'
                        : 'red';

                $badge_label = 'success' === $row->status
                        ? __( 'Успешно', 'fs-lms' )
                        : __( 'Ошибка', 'fs-lms' );
                ?>
                <tr>
                    <td><?php echo (int) $row->id; ?></td>
                    <td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
                    <td><?php echo LogNameResolver::userNameWithRole( $row->actorUserId ); // phpcs:ignore ?></td>
                    <td><?php echo esc_html( EmailTemplateType::tryFrom( $row->emailType )?->label() ?? $row->emailType ); ?></td>
                    <td><?php echo esc_html( $row->targetPersonId ? LogNameResolver::personName( $row->targetPersonId ) : LogNameResolver::personNameByEmail( $row->recipientEmail ) ); ?></td>
                    <td><?php echo $row->recipientEmail ? esc_html( $row->recipientEmail ) : '—'; ?></td>
                    <td>
                        <?php
                        // 3. Рендерим бейдж с помощью render_fs_badge
                        render_fs_badge( $badge_label, $badge_color );
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $email_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
