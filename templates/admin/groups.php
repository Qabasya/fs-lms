<?php
/**
 * Шаблон страницы управления группами.
 *
 * @var array                       $subjects            Список предметов
 * @var array                       $academic_periods    Полный список академических периодов для маппинга названий в таблице
 * @var array|null                  $current_period      Текущий период структуры ['id' => string, 'name' => string] или null
 * @var array<string, string>       $other_periods       Массив остальных периодов ['id' => 'name']
 * @var string                      $selected_period_id  ID выбранного периода фильтра
 * @var \Inc\DTO\StudentGroupDTO[]  $groups              Массив DTO групп
 * @var \Inc\DTO\UserDTO[]           $teachers            Список преподавателей
 */

declare( strict_types=1 );
?>

	<div class="wrap">
		<h1 class="wp-heading-inline">Группы учеников</h1>

		<div class="tablenav top groups-filter-nav">
			<div class="actions alignleft">
				<label for="filter-by-period">Выберите период:</label>
				<select name="period_filter" id="filter-by-period" class="js-filter-by-period">
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
			</div>

			<button type="button" class="page-title-action js-open-group-modal">Добавить группу</button>
		</div>

		<hr class="wp-header-end">

		<div class="tab-content groups-tab-content">
			<table class="wp-list-table widefat fixed striping table-view-list groups-table">
				<thead>
				<tr>
					<th scope="col" class="manage-column column-title column-primary">Название группы</th>
					<th scope="col" class="manage-column column-period">Учебный период</th>
					<th scope="col" class="manage-column column-subject">Предмет</th>
					<th scope="col" class="manage-column column-teacher">Преподаватель</th>
					<th scope="col" class="manage-column column-actions">Действия</th>
				</tr>
				</thead>

				<tbody id="the-list">
				<?php if ( empty( $groups ) ) : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="5">
							<?php echo '' === $selected_period_id ? 'Нет учебных периодов.' : 'В выбранном периоде ещё нет групп.'; ?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $groups as $group ) :
						$period_name  = $academic_periods[ $group->period_id ]['name'] ?? $group->period_id;
						$subject_name = $subjects[ $group->subject_id ]->name ?? $group->subject_id;

						$teacher_name = 'Не назначен';
						foreach ( $teachers as $teacher ) {
							if ( $teacher->id === $group->teacher_id ) {
								$teacher_name = $teacher->displayName;
								break;
							}
						}
						?>
						<tr id="group-row-<?php echo esc_attr( (string) $group->id ); ?>"
							class="js-toggle-students"
							data-group-id="<?php echo esc_attr( (string) $group->id ); ?>">
							<td class="column-title column-primary data-title">
								<span class="dashicons dashicons-arrow-right-alt2 accordion-arrow"></span>
								<strong><?php echo esc_html( $group->title ); ?></strong>
							</td>
							<td><?php echo esc_html( $period_name ); ?></td>
							<td><?php echo esc_html( $subject_name ); ?></td>
							<td><?php echo esc_html( $teacher_name ); ?></td>
							<td>
						<span class="trash">
							<a href="#"
								class="submitdelete js-delete-group"
								data-id="<?php echo esc_attr( (string) $group->id ); ?>">
								Удалить
							</a>
						</span>
							</td>
						</tr>

						<tr id="students-row-<?php echo esc_attr( (string) $group->id ); ?>" class="students-accordion-row hidden">
							<td colspan="5">
								<div class="students-accordion-content">
									<p class="description">
										<span class="dashicons dashicons-groups"></span>
										Ученики еще не добавлены
									</p>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php
// Подключаем модальное окно добавления группы (переданные $teachers, $subjects и $academic_periods прокинутся автоматически)
$group_modal_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/components/modals/group-modal.php';

if ( file_exists( $group_modal_path ) ) {
	include $group_modal_path;
}

?>