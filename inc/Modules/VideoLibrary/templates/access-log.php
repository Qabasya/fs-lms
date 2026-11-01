<?php
/**
 * Video access log tab.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use Inc\Enums\Wp\Menu;
use Inc\Modules\VideoLibrary\Services\VideoAccessLogPageService;
use Inc\Services\Log\LogNameResolver;

$base_url    = add_query_arg(
	array(
		'page' => Menu::Logs->value,
		'tab'  => VideoAccessLogPageService::TAB,
	),
	admin_url( 'admin.php' )
);
$filters     = $video_log['filters'];
$sort_url    = add_query_arg( $filters, $base_url );
$filter_url  = add_query_arg(
	array(
		'orderby' => $video_log['orderby'],
		'order'   => $video_log['order'],
	),
	$sort_url
);
$total_pages = (int) ceil( $video_log['total'] / $video_log['per_page'] );
?>
<div class="fs-logs-tab" id="js-video-access-log-tab">
	<p class="description">Выдачи ссылок и время просмотра по данным плеера. Обращения одного пользователя к записи объединяются в сессию с окном 3 часа.</p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( Menu::Logs->value ); ?>">
		<input type="hidden" name="tab" value="<?php echo esc_attr( VideoAccessLogPageService::TAB ); ?>">
		<input type="hidden" name="orderby" value="<?php echo esc_attr( $video_log['orderby'] ); ?>">
		<input type="hidden" name="order" value="<?php echo esc_attr( $video_log['order'] ); ?>">
		<input type="number" min="1" name="wp_user_id" value="<?php echo esc_attr( (string) ( $filters['wp_user_id'] ?? '' ) ); ?>" placeholder="ID пользователя" aria-label="ID пользователя">
		<input type="number" min="1" name="group_lesson_id" value="<?php echo esc_attr( (string) ( $filters['group_lesson_id'] ?? '' ) ); ?>" placeholder="ID занятия" aria-label="ID занятия">
		<input type="search" name="recording" value="<?php echo esc_attr( $filters['recording'] ?? '' ); ?>" placeholder="Запись" aria-label="Запись">
		<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>" aria-label="С даты">
		<span>—</span>
		<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>" aria-label="По дату">
		<button type="submit" class="button">Применить</button>
		<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
	</form>
	<p class="fs-logs-summary">Найдено записей: <strong><?php echo esc_html( number_format_i18n( $video_log['total'] ) ); ?></strong>
		· Пользователей: <strong><?php echo esc_html( number_format_i18n( $video_log['summary']['users'] ) ); ?></strong>
		· Выдач ссылок: <strong><?php echo esc_html( number_format_i18n( $video_log['summary']['requests'] ) ); ?></strong>
		· Просмотр, с: <strong><?php echo esc_html( number_format_i18n( $video_log['summary']['seconds'] ) ); ?></strong>
	</p>
	<?php if ( array() === $video_log['rows'] ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead><tr>
				<?php
				foreach ( array(
					'id'      => 'ID',
					'last_at' => 'Последний просмотр',
				) as $key => $label ) :
					?>
					<th><?php echo wp_kses_post( LogNameResolver::sortableHeader( $label, $key, $video_log['orderby'], $video_log['order'], $sort_url ) ); ?></th>
				<?php endforeach; ?>
				<th>Пользователь</th><th>Запись</th><th>Занятие</th>
				<?php
				foreach ( array(
					'requests_count' => 'Выдач',
					'watch_seconds'  => 'Просмотр, с',
				) as $key => $label ) :
					?>
					<th><?php echo wp_kses_post( LogNameResolver::sortableHeader( $label, $key, $video_log['orderby'], $video_log['order'], $sort_url ) ); ?></th>
				<?php endforeach; ?>
				<th>IP</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $video_log['rows'] as $row ) : ?>
				<tr>
					<td><?php echo (int) $row['id']; ?></td>
					<td><?php echo esc_html( (string) $row['last_at'] ); ?></td>
					<td><?php echo esc_html( $video_log['names'][ (int) $row['wp_user_id'] ] ?? '#' . $row['wp_user_id'] ); ?></td>
					<td><code><?php echo esc_html( $row['s3_bucket'] . '/' . $row['s3_key'] ); ?></code></td>
					<td><?php echo null !== $row['group_lesson_id'] ? (int) $row['group_lesson_id'] : '—'; ?></td>
					<td><?php echo (int) $row['requests_count']; ?></td>
					<td><?php echo (int) $row['watch_seconds']; ?></td>
					<td><?php echo esc_html( (string) $row['ip'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $filter_url ),
						'format'    => '',
						'current'   => $video_log['page'],
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
