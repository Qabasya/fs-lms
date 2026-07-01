<?php

declare( strict_types=1 );

use Inc\DTO\Log\AuthLogDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Services\Log\LogNameResolver;
require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

/**
 * @var AuthLogDTO[] $auth_rows
 * @var int                       $auth_total
 * @var int                       $auth_page
 * @var array                     $auth_filters
 * @var int                       $per_page
 * @var string                    $active_tab
 * @var string              $log_orderby
 * @var string              $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $auth_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-8' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $auth_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $auth_filters, $base_url );

?>

<div class="fs-logs-tab" id="js-auth-log-tab">
    <p class="description">
        Каждое действие авторизации или отправки OTP фиксируется здесь.
    </p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-8">

		<select name="action">
			<option value="">Все действия</option>
            <?php foreach ( $auth_actions ?? array() as $actionValue ) :
            $action = AuthAction::tryFrom( $actionValue );
            ?>

                <option value="<?php echo esc_attr( $actionValue ); ?>"
                        <?php selected( $auth_filters['action'] ?? '', $actionValue ); ?>>
                    <?php echo esc_html( $action?->label() ?? $actionValue ); ?>
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

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="auth"
			data-filters="<?php echo esc_attr( wp_json_encode( $auth_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
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
                <th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
                <th class="tw-7">Дата</th>
				<th class="tw-20">Логин</th>
				<th>Действие</th>
				<th class="tw-10" >Результат</th>
                <th class="tw-5">IP</th>
			</tr>
			</thead>
			<tbody>


			<?php foreach ( $auth_rows as $row ) :
				$authResult = AuthResult::tryFrom( $row->result )?->value;

                $badge_color = 'success' === $authResult
                        ? 'green'
                        : 'red';

                $badge_label = AuthResult::tryFrom( $row->result )?->label();

				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td><?php echo LogNameResolver::personNameByLogin( $row->loginIdentifier ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( AuthAction::tryFrom( $row->action )?->label() ?? $row->action ); ?></td>
                    <td>
                        <?php
                        render_fs_badge( $badge_label, $badge_color );
                        ?>
                    </td>
					<td><?php echo esc_html( $row->actorIp ); ?></td>
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
