<?php
/**
 * Шаблон страницы управления группами.
 *
 * @var array                       $subjects          Список предметов (из коллбека)
 * @var array                       $academic_periods  Список академических периодов (из коллбека)
 * @var \Inc\DTO\StudentGroupDTO[]  $groups            Массив DTO групп (из коллбека)
 * @var \WP_User[]                  $teachers          Список преподавателей (из коллбека)
 */

declare( strict_types=1 );
?>

	<div class="wrap">
		<h1 class="wp-heading-inline">Группы учеников</h1>
		<button type="button" class="page-title-action js-open-group-modal">Добавить группу</button>

		<hr class="wp-header-end">

		<div class="tab-content" style="margin-top: 20px;">
			<table class="wp-list-table widefat fixed striping table-view-list groups-table">
				<thead>
				<tr>
					<th scope="col" class="manage-column column-title column-primary">Название группы</th>
					<th scope="col" class="manage-column column-period">Учебный период</th>
					<th scope="col" class="manage-column column-subject">Предмет</th>
					<th scope="col" class="manage-column column-teacher">Преподаватель</th>
					<th scope="col" class="manage-column column-actions" style="width: 100px;">Действия</th>
				</tr>
				</thead>

				<tbody id="the-list">
				<?php if ( empty( $groups ) ) : ?>
					<tr class="no-items">
						<td class="colspanchange" colspan="5">Группы ещё не созданы.</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $groups as $group ) :
						$period_name  = $academic_periods[ $group->period_id ]['name'] ?? $group->period_id;
						$subject_name = $subjects[ $group->subject_id ]->name ?? $group->subject_id;

						$teacher_name = 'Не назначен';
						foreach ( $teachers as $teacher ) {
							if ( $teacher->ID === $group->teacher_id ) {
								$teacher_name = $teacher->display_name;
								break;
							}
						}
						?>
						<tr id="group-row-<?php echo esc_attr( $group->id ); ?>"
							class="js-toggle-students"
							data-group-id="<?php echo esc_attr( $group->id ); ?>"
							style="cursor: pointer;">
							<td class="column-title column-primary data-title">
								<span class="dashicons dashicons-arrow-right-alt2 accordion-arrow" style="transition: transform 0.2s; margin-right: 5px;"></span>
								<strong><?php echo esc_html( $group->title ); ?></strong>
							</td>
							<td><?php echo esc_html( $period_name ); ?></td>
							<td><?php echo esc_html( $subject_name ); ?></td>
							<td><?php echo esc_html( $teacher_name ); ?></td>
							<td>
						<span class="trash">
							<a href="#"
								class="submitdelete js-delete-group"
								data-id="<?php echo esc_attr( $group->id ); ?>">
								Удалить
							</a>
						</span>
							</td>
						</tr>

						<tr id="students-row-<?php echo esc_attr( $group->id ); ?>" class="students-accordion-row hidden" style="background: #f6f7f7;">
							<td colspan="5" >
								<div class="students-accordion-content">
									<p class="description" style="margin: 0; font-style: italic; color: #646970;">
										<span class="dashicons dashicons-groups" style="font-size: 18px; vertical-align: middle; margin-right: 5px;"></span>
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