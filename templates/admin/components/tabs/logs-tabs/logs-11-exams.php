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

$actor_types = array(
	'user'   => 'Сотрудник',
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
				<th class="tw-5"><?php echo LogNameResolver::sortableHeader( 'ID', 'id', $log_orderby, $log_order, $sort_url ); // phpcs:ignore ?></th>
				<th class="tw-10">Дата</th>
				<th class="tw-20">Действие</th>
				<th class="tw-15">Кто</th>
				<th class="tw-15">Участник</th>
				<th>Объект</th>
				<th class="tw-7">Результат</th>
				<th class="tw-10">IP</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $exam_rows as $row ) :
				$refs = array_filter(
					array(
						'Проведение' => $row->eventId,
						'Сеанс'      => $row->sessionId,
						'Заявка'     => $row->registrationId,
						'Попытка'    => $row->attemptId,
					)
				);
				$actor = $row->actorLabel ?? ( $actor_types[ $row->actorType ] ?? $row->actorType );
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
						<div class="fs-text-muted"><?php echo esc_html( $actor_types[ $row->actorType ] ?? $row->actorType ); ?></div>
					</td>
					<td><?php echo esc_html( $row->subjectLabel ?? '—' ); ?></td>
					<td>
						<?php foreach ( $refs as $label => $ref_id ) : ?>
							<div><?php echo esc_html( $label ); ?> <code>#<?php echo (int) $ref_id; ?></code></div>
						<?php endforeach; ?>
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
