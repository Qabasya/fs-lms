<?php

declare( strict_types=1 );

use Inc\DTO\Log\ExamLogDTO;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Services\Log\LogNameResolver;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

/**
 * Вкладка «Экзамены»: действия с проведениями, сеансами, заявками и попытками.
 * Контекст действия (причина, было/стало, код ошибки) раскрывается под строкой.
 *
 * @var ExamLogDTO[] $exam_rows
 * @var int          $exam_total
 * @var int          $exam_page
 * @var array        $exam_filters
 * @var string[]     $exam_actions
 * @var array        $exam_names   events/sessions/actors: названия проведений, даты сеансов, роль ученика/родителя
 * @var int          $per_page
 * @var string       $log_orderby
 * @var string       $log_order
 */

$page_slug   = sanitize_key( $_GET['page'] ?? 'fs_lms_logs' ); // phpcs:ignore
$per_page    = max( 1, $per_page );
$total_pages = (int) ceil( $exam_total / $per_page );
$base_url    = add_query_arg( array( 'page' => $page_slug, 'tab' => 'tab-11' ), admin_url( 'admin.php' ) );
$sort_params = array_filter( array( 'orderby' => 'id' !== $log_orderby ? $log_orderby : null, 'order' => 'desc' !== $log_order ? $log_order : null ) );
$filter_url  = add_query_arg( array_merge( $exam_filters, $sort_params ), $base_url );
$sort_url    = add_query_arg( $exam_filters, $base_url );

// «Кто»: ученик/родитель/гость — ролью, система — словом, сотрудник — ФИО.
$actor_types = array(
	'guest'  => 'Гость',
	'system' => 'Система',
);
?>

<div class="fs-logs-tab" id="js-exam-log-tab">
	<p class="description">
		Действия с экзаменами: проведения, сеансы, заявки, допуск, начало и сдача работы, проверка, оплаты и технические сбои.
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
		<input type="hidden" name="tab"  value="tab-11">

		<input type="search" name="person" value="<?php echo esc_attr( $exam_filters['person'] ?? '' ); ?>" placeholder="ФИО участника">

		<select name="action">
			<option value="">Все действия</option>
			<?php foreach ( $exam_actions as $action_value ) : ?>
				<option value="<?php echo esc_attr( $action_value ); ?>" <?php selected( $exam_filters['action'] ?? '', $action_value ); ?>>
					<?php echo esc_html( ExamLogAction::tryFrom( $action_value )?->label() ?? $action_value ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="result">
			<option value="">Все результаты</option>
			<option value="success" <?php selected( $exam_filters['result'] ?? '', 'success' ); ?>>Успех</option>
			<option value="failure" <?php selected( $exam_filters['result'] ?? '', 'failure' ); ?>>Отказ / сбой</option>
		</select>

		<input type="number" min="1" name="event_id" value="<?php echo esc_attr( (string) ( $exam_filters['event_id'] ?? '' ) ); ?>" placeholder="Проведение №">
		<input type="number" min="1" name="session_id" value="<?php echo esc_attr( (string) ( $exam_filters['session_id'] ?? '' ) ); ?>" placeholder="Сеанс №">
		<input type="number" min="1" name="registration_id" value="<?php echo esc_attr( (string) ( $exam_filters['registration_id'] ?? '' ) ); ?>" placeholder="Заявка №">

		<input type="date" name="date_from" value="<?php echo esc_attr( $exam_filters['date_from'] ?? '' ); ?>">
		<span>—</span>
		<input type="date" name="date_to"   value="<?php echo esc_attr( $exam_filters['date_to'] ?? '' ); ?>">

		<button type="submit" class="button">Применить</button>
		<?php if ( ! empty( $exam_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>

	<p class="fs-logs-summary">Найдено записей: <strong><?php echo number_format_i18n( $exam_total ); ?></strong></p>

	<?php if ( empty( $exam_rows ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Записи не найдены.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th class="tw-3"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
				<th class="tw-7">Дата</th>
				<th class="tw-20">Действие</th>
				<th class="tw-10">Кто</th>
				<th class="tw-10">Участник</th>
				<th>Объект</th>
				<th class="tw-5">Результат</th>
				<th class="tw-10">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $exam_rows as $row ) :
				$event_title = (string) ( $exam_names['events'][ $row->eventId ] ?? '' );
				$session_at  = (string) ( $exam_names['sessions'][ $row->sessionId ] ?? '' );
				$actor       = $actor_types[ $row->actorType ]
					?? ( '' !== (string) ( $exam_names['actors'][ $row->actorUserId ] ?? '' ) ? $exam_names['actors'][ $row->actorUserId ] : null )
					?? $row->actorLabel
					?? 'Пользователь #' . (int) $row->actorUserId;
				?>
				<tr>
					<td><?php echo (int) $row->id; ?></td>
					<td><?php echo esc_html( LogNameResolver::date( $row->createdAt ) ); ?></td>
					<td>
						<?php echo esc_html( ExamLogAction::tryFrom( $row->action )?->label() ?? $row->action ); ?>
						<?php if ( ! empty( $row->context ) ) : ?>
							<details class="fs-code-sm">
								<summary>Подробнее</summary>
								<?php foreach ( $row->context as $key => $value ) : ?>
									<div><?php echo esc_html( (string) $key ); ?>: <?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ); ?></div>
								<?php endforeach; ?>
							</details>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $actor ); ?>
					</td>
					<td><?php echo esc_html( $row->subjectLabel ?? '—' ); ?></td>
					<td>
						<?php if ( '' !== $event_title ) : ?>
							<div>Проведение «<?php echo esc_html( $event_title ); ?>»</div>
						<?php endif; ?>
						<?php if ( '' !== $session_at ) : ?>
							<div>Сеанс <?php echo esc_html( mysql2date( 'd.m.Y H:i', $session_at ) ); ?></div>
						<?php endif; ?>
						<?php if ( '' === $event_title && '' === $session_at ) : ?>—<?php endif; ?>
					</td>
					<td><?php render_fs_badge( 'success' === $row->result ? 'Успех' : 'Сбой', 'success' === $row->result ? 'green' : 'red' ); ?></td>
					<td><?php echo esc_html( $row->actorIp ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter_url ), 'format' => '', 'current' => $exam_page, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ); // phpcs:ignore ?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
