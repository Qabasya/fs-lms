<?php

declare( strict_types=1 );
/**
 * Таб «Гости экзамена» — гости, записавшиеся по ссылкам: данные формы записи и сеанс.
 * Данные отдаёт `ExamGuestDirectoryService` (передан из `AdminCallbacks::userlistPage()` как `$exam_guests`).
 * Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Access\Capability;
use Inc\Services\Log\LogNameResolver;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( Capability::ManageExamGuests->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$eventFilter   = (int) ( $_GET['event_id'] ?? 0 );
$sessionFilter = (int) ( $_GET['session_id'] ?? 0 );
$schoolFilter  = sanitize_text_field( wp_unslash( $_GET['school'] ?? '' ) );
$orderbyRaw    = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
$orderby       = in_array( $orderbyRaw, array( 'name', 'session' ), true ) ? $orderbyRaw : '';
$order         = 'desc' === sanitize_key( wp_unslash( $_GET['order'] ?? '' ) ) ? 'desc' : 'asc';

$filters = array_filter( array(
	'event_id'   => $eventFilter,
	'session_id' => $sessionFilter,
	'school'     => $schoolFilter,
) );

$result  = $exam_guests->page( $page, $perPage, $filters, $orderby, $order );
$options = $exam_guests->filterOptions();
$guests  = $result['rows'];
$total   = $result['total'];
$pages   = (int) ceil( $total / $perPage );

$pageSlug      = sanitize_key( $_GET['page'] ?? '' );
$baseUrl       = add_query_arg( array( 'page' => $pageSlug, 'tab' => 'tab-7' ), admin_url( 'admin.php' ) );
$activeFilters = array_filter( array(
	'event_id'   => $eventFilter ?: '',
	'session_id' => $sessionFilter ?: '',
	'school'     => $schoolFilter,
) );
$sortUrl       = add_query_arg( $activeFilters, $baseUrl );
$pageUrl       = add_query_arg( array_filter( array( 'orderby' => $orderby, 'order' => $order ) ), $sortUrl );
?>

<div class="fs-lms-exam-guests fs-logs-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters fs-logs-filters--wide">
		<input type="hidden" name="page" value="<?php echo esc_attr( $pageSlug ); ?>">
		<input type="hidden" name="tab" value="tab-7">

		<?php render_fs_select( array(
			'name'      => 'event_id',
			'options'   => $options['events'],
			'selected'  => $eventFilter ?: '',
			'all_label' => 'Все экзамены',
		) ); ?>

		<?php render_fs_select( array(
			'name'      => 'school',
			'options'   => $options['schools'],
			'selected'  => $schoolFilter,
			'all_label' => 'Все школы',
		) ); ?>

		<?php render_fs_select( array(
			'name'      => 'session_id',
			'options'   => $options['sessions'],
			'selected'  => $sessionFilter ?: '',
			'all_label' => 'Все сеансы',
		) ); ?>

		<button type="submit" class="button">Применить</button>

		<?php if ( array() !== $activeFilters ) : ?>
			<a href="<?php echo esc_url( $baseUrl ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>

	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $total ); ?></strong>
		<?php if ( array() !== $activeFilters ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">
		<thead>
		<tr>
			<th class="column-title column-primary tw-15">
				<?php echo LogNameResolver::sortableHeader( 'ФИО', 'name', $orderby, $order, $sortUrl ); // phpcs:ignore ?>
			</th>
			<th class="column-title tw-10">Телефон</th>
			<th class="column-title tw-10">Мессенджер</th>
			<th class="column-title tw-15">Школа</th>
			<th class="column-title tw-5">Класс</th>
			<th class="column-title tw-10">Заявка</th>
			<th class="column-title tw-15">Экзамен</th>
			<th class="column-title tw-10">
				<?php echo LogNameResolver::sortableHeader( 'Сеанс', 'session', $orderby, $order, $sortUrl ); // phpcs:ignore ?>
			</th>
		</tr>
		</thead>
		<tbody id="the-list">
		<?php if ( array() === $guests ) : ?>
			<tr>
				<td colspan="8">
					<div class="notice notice-info inline fs-table__no-items">
						<p>Гостей нет.</p>
					</div>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $guests as $guest ) : ?>
				<tr>
					<td class="column-title">
						<?php echo esc_html( $guest['name'] ); ?>
						<?php if ( '' !== $guest['apply_link'] ) : ?>
							<br><a href="<?php echo esc_url( $guest['apply_link'] ); ?>" class="js-guest-apply-link" data-link="<?php echo esc_attr( $guest['apply_link'] ); ?>">Выдать ссылку</a>
						<?php endif; ?>
					</td>
					<td class="column-title"><?php echo esc_html( '' !== $guest['phone'] ? $guest['phone'] : '—' ); ?></td>
					<td class="column-title"><?php echo esc_html( '' !== $guest['messenger'] ? $guest['messenger'] : '—' ); ?></td>
					<td class="column-title"><?php echo esc_html( '' !== $guest['school'] ? $guest['school'] : '—' ); ?></td>
					<td class="column-title"><?php echo esc_html( '' !== $guest['grade'] ? $guest['grade'] : '—' ); ?></td>
					<td class="column-date"><?php echo esc_html( $guest['created_at'] ); ?></td>
					<td class="column-title"><?php echo esc_html( '' !== $guest['event'] ? $guest['event'] : '—' ); ?></td>
					<td class="column-title">
						<?php echo esc_html( '' !== $guest['session'] ? $guest['session'] : 'не записан' ); ?>
						<?php if ( '' !== $guest['status'] ) : ?>
							<span class="fs-logs__subline fs-code-sm fs-text-muted"><?php echo esc_html( $guest['status'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php render_fs_pagination( $page, $pages, add_query_arg( 'paged', '%#%', $pageUrl ) ); ?>

</div>
