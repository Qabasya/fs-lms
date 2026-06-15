<?php
/**
 * @var array                     $subjects            Список предметов
 * @var array                     $academic_periods    Список периодов
 * @var array|null                $current_period      Текущий период ['id' => string, 'name' => string]
 * @var array<string, string>     $other_periods       Остальные периоды ['id' => 'name']
 * @var string                    $selected_period_id  ID выбранного периода
 * @var array                     $groups_filters      Активные фильтры (subject_key, teacher_id)
 * @var array                     $groups_view         Данные групп
 * @var \Inc\DTO\Person\UserDTO[] $teachers            Список преподавателей
 */

declare( strict_types=1 );

$page_slug = sanitize_key( $_GET['page'] ?? 'fs_lms_groups' ); // phpcs:ignore
$base_url  = add_query_arg( array( 'page' => $page_slug, 'period_filter' => $selected_period_id ), admin_url( 'admin.php' ) );
?>

<div class="wrap">
    <div class="header-row">
        <h1 class="wp-heading-inline">Управление группами</h1>
    </div>


	<div class="groups-header-actions">
		<button type="button" class="page-title-action js-open-group-modal">Добавить группу</button>
		<button type="button" class="page-title-action js-export-groups">
            <span class="dashicons dashicons-download"></span>
            Экспорт CSV
        </button>
	</div>

	<hr class="wp-header-end">

	<div class="fs-logs-tab">

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">

		<select name="period_filter">
			<?php if ( null !== $current_period ) : ?>
				<option value="<?php echo esc_attr( $current_period['id'] ); ?>" <?php selected( $selected_period_id, $current_period['id'] ); ?>>
					<?php echo esc_html( $current_period['name'] ); ?> (Текущий)
				</option>
			<?php endif; ?>

			<?php if ( null !== $current_period && ! empty( $other_periods ) ) : ?>
				<option disabled>────────────────────</option>
			<?php endif; ?>

			<?php foreach ( $other_periods as $id => $name ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected_period_id, $id ); ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="subject_key">
			<option value="">Все предметы</option>
			<?php foreach ( $subjects as $key => $subject ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $groups_filters['subject_key'] ?? '', $key ); ?>>
					<?php echo esc_html( $subject->name ?? $key ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="teacher_id">
			<option value="">Все преподаватели</option>
			<?php foreach ( $teachers as $teacher ) : ?>
				<option value="<?php echo esc_attr( (string) $teacher->id ); ?>" <?php selected( (string) ( $groups_filters['teacher_id'] ?? '' ), (string) $teacher->id ); ?>>
					<?php echo esc_html( $teacher->displayName ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<button type="submit" class="button">Применить</button>

		<?php if ( ! empty( $groups_filters ) ) : ?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>



	<table class="wp-list-table widefat fixed striped fs-table">
		<thead>
		<tr>
			<th scope="col" class="column-title column-primary tw-10">Название группы</th>
			<th scope="col" class="column-period tw-10">Учебный период</th>
			<th scope="col" class="column-subject tw-10">Предмет</th>
			<th scope="col" class="column-teacher tw-20">Преподаватель</th>
			<th scope="col" class="column-schedule tw-20">Расписание</th>
			<th scope="col" class="column-count tw-5">Активных</th>
			<th scope="col" class="column-actions">Действия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $groups_view ) ) : ?>
			<tr class="no-items">
				<td class="colspanchange" colspan="7">
					<?php if ( '' === $selected_period_id ) : ?>
						Нет учебных периодов.
					<?php elseif ( ! empty( $groups_filters ) ) : ?>
						Группы с такими фильтрами не найдены.
					<?php else : ?>
						В выбранном периоде ещё нет групп.
					<?php endif; ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $groups_view as $group ) : ?>
				<tr id="group-row-<?php echo esc_attr( $group['id'] ); ?>"
					data-group-id="<?php echo esc_attr( $group['id'] ); ?>"
					data-group-name="<?php echo esc_attr( $group['title'] ); ?>"
					data-teacher-id="<?php echo esc_attr( (string) ( $group['teacher_id'] ?? '' ) ); ?>"
					data-schedule="<?php echo esc_attr( $group['schedule_raw'] ); ?>"
					data-period-id="<?php echo esc_attr( $group['period_id'] ); ?>"
					data-subject-key="<?php echo esc_attr( $group['subject_key'] ); ?>">
					<td class="column-title">
						<strong><?php echo esc_html( $group['title'] ); ?></strong>
					</td>
					<td><?php echo esc_html( $group['period_name'] ); ?></td>
					<td><?php echo esc_html( $group['subject_name'] ); ?></td>
					<td><?php echo esc_html( $group['teacher_name'] ); ?></td>
					<td><?php echo nl2br( esc_html( $group['schedule'] ) ); ?></td>
					<td><?php echo (int) $group['active_count']; ?></td>
					<td>
						<div class="row-actions visible">
							<span class="view">
								<a href="#" class="js-view-group-students"
									data-group-id="<?php echo esc_attr( $group['id'] ); ?>"
									data-group-name="<?php echo esc_attr( $group['title'] ); ?>">Просмотреть</a>
							</span>
							|
							<span class="edit">
								<a href="#" class="js-edit-group">Редактировать</a>
							</span>
							|
							<span class="trash">
								<a href="#" class="submitdelete js-delete-group"
									data-id="<?php echo esc_attr( $group['id'] ); ?>">Удалить</a>
							</span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	</div><!-- /.fs-logs-tab -->
</div>

<?php
$modal_dir = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/components/modals/enrollment/';

$group_modal_path = $modal_dir . 'group-modal.php';
if ( file_exists( $group_modal_path ) ) {
	include $group_modal_path;
}

$students_modal_path = $modal_dir . 'group-students-modal.php';
if ( file_exists( $students_modal_path ) ) {
	include $students_modal_path;
}
?>
