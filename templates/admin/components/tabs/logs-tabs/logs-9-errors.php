<?php

declare( strict_types=1 );

use Inc\DTO\Log\ErrorLogDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Log\LogNameResolver;
require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

/**
 * Вкладка «Ошибки»: что пошло не так у пользователей. Номер инцидента (#ref)
 * пользователь видит рядом с кодом ошибки — по нему запись ищется со скриншота.
 *
 * @var ErrorLogDTO[] $error_rows
 * @var int           $error_total
 * @var int           $error_page
 * @var array         $error_filters
 * @var string[]      $error_codes
 * @var int           $per_page
 * @var string        $log_orderby
 * @var string        $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $error_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-9' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $error_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $error_filters, $base_url );

?>

<div class="fs-logs-tab" id="js-errors-log-tab">
	<p class="description">
		Ошибки, которые увидели пользователи. Номер инцидента (#A1B2C3) показывается пользователю рядом с кодом —
		введите его в поиск, чтобы найти запись по скриншоту.
	</p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-9">
		<?php if ( ! empty( $error_filters['user_id'] ) ) : ?>
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $error_filters['user_id'] ); ?>">
		<?php endif; ?>

		<input type="search" name="ref" placeholder="Номер инцидента"
			value="<?php echo esc_attr( $error_filters['ref'] ?? '' ); ?>">

		<select name="code">
			<option value="">Все коды</option>
			<?php foreach ( $error_codes ?? array() as $codeValue ) : ?>
				<option value="<?php echo esc_attr( $codeValue ); ?>" <?php selected( $error_filters['code'] ?? '', $codeValue ); ?>>
					<?php echo esc_html( $codeValue . ' — ' . ( ErrorCode::fromCode( $codeValue )?->label() ?? '' ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="source">
			<option value="">Любой источник</option>
			<option value="server" <?php selected( $error_filters['source'] ?? '', 'server' ); ?>>Сервер</option>
			<option value="client" <?php selected( $error_filters['source'] ?? '', 'client' ); ?>>Браузер</option>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $error_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $error_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $error_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>

		<button type="button" class="button js-export-log-csv fs-logs__export-btn"
			data-channel="errors"
			data-filters="<?php echo esc_attr( wp_json_encode( $error_filters ) ); ?>">
			<span class="dashicons dashicons-download"></span>
			Экспорт CSV
		</button>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $error_total ); ?></strong></p>

	<?php if ( empty( $error_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th class="tw-7"><?php echo LogNameResolver::sortableHeader( 'Инцидент', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
				<th class="tw-10">Дата</th>
				<th class="tw-15">Пользователь</th>
				<th class="tw-15">Код</th>
				<th>Сообщение</th>
				<th class="tw-15">Где</th>
				<th class="tw-10">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $error_rows as $row ) :
				$code     = ErrorCode::fromCode( $row->code );
				$userUrl  = $row->userId ? add_query_arg( 'user_id', $row->userId, $base_url ) : '';
				$urlPath  = $row->url ? (string) wp_parse_url( $row->url, PHP_URL_PATH ) : '';
				$details  = $row->context;
				if ( $row->actorUa ) {
					$details['user_agent'] = $row->actorUa;
				}
				?>
				<tr>
					<td><code>#<?php echo esc_html( $row->ref ); ?></code></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td>
						<?php if ( $userUrl ) : ?>
							<a href="<?php echo esc_url( $userUrl ); ?>" title="Все ошибки пользователя">
								<?php echo LogNameResolver::userName( $row->userId ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- экранирует userName() ?>
							</a>
						<?php else : ?>
							<span class="fs-text-muted">Гость</span>
						<?php endif; ?>
					</td>
					<td>
						<?php render_fs_badge( $row->code, 'client' === $row->source ? 'yellow' : 'red' ); ?>
						<?php if ( null !== $code ) : ?>
							<span class="fs-logs__subline fs-code-sm fs-text-muted"><?php echo esc_html( $code->label() ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $row->message ); ?>
						<?php if ( ! empty( $details ) ) : ?>
							<details class="fs-code-sm">
								<summary>Подробнее</summary>
								<?php foreach ( $details as $key => $value ) : ?>
									<div><b><?php echo esc_html( (string) $key ); ?>:</b> <?php echo esc_html( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ); ?></div>
								<?php endforeach; ?>
							</details>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $row->action ) : ?>
							<code><?php echo esc_html( $row->action ); ?></code>
						<?php endif; ?>
						<?php if ( '' !== $urlPath ) : ?>
							<span class="fs-logs__subline fs-code-sm fs-text-muted"><?php echo esc_html( $urlPath ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row->actorIp ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $error_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
