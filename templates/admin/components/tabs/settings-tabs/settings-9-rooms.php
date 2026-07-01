<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Таб «Кабинеты» (Эпик 9). Близнец таба «Учебные периоды».
 *
 * @var \Inc\DTO\Course\RoomDTO[] $rooms        Список кабинетов
 * @var array<int,array<int,array{name:string,schedule:string}>> $rooms_groups  id кабинета → группы с расписанием
 */

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
?>

<div id="tab-rooms" class="tab-pane active">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h1 class="fs-page-header__title">Кабинеты</h1>
		</div>
	</div>

	<?php settings_errors(); ?>

	<?php if ( empty( $rooms ) ) : ?>

		<div class="notice notice-info inline fs-table__no-items">
			<p>Вы ещё не создали ни одного кабинета.</p>
			<button type="button" class="page-title-action js-add-room">
				Добавить первый кабинет
			</button>
		</div>

	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table fs-table--boilerplate">
			<thead>
			<tr>
				<th class=" column-title tw-30">Название кабинета</th>
				<th class=" column-title column-primary">Группы</th>
				<th class=" column-title column-primary">Действия</th>
			</tr>
			</thead>

			<tbody id="the-list">
			<?php foreach ( $rooms as $room ) : ?>
				<?php
				$row_id       = (int) $room->id;
				$row_name     = esc_attr( $room->name );
				$row_subjects = esc_attr( implode( ',', $room->allowedSubjects ) );
				$groups       = $rooms_groups[ $row_id ] ?? array();
				?>
				<tr id="room-row-<?php echo $row_id; ?>">
					<td class="column-title">
						<strong>
							<a class="row-title js-edit-room"
								href="#"
								data-id="<?php echo $row_id; ?>"
								data-name="<?php echo $row_name; ?>"
								data-subjects="<?php echo $row_subjects; ?>">
								<?php echo esc_html( $room->name ); ?>
							</a>
						</strong>
					</td>

					<td>
						<?php if ( empty( $groups ) ) : ?>
							<span class="fs-dashicon fs-dashicon--muted">—</span>
						<?php else : ?>
							<?php foreach ( $groups as $i => $grp ) : ?>
								<span class="fs-tip" data-tooltip="<?php echo esc_attr( '' !== $grp['schedule'] ? $grp['schedule'] : 'Расписание не задано' ); ?>"><?php echo esc_html( $grp['name'] ); ?></span><?php echo $i < count( $groups ) - 1 ? ', ' : ''; ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>

					<td class="column-actions">
						<div class="row-actions visible">
							<span class="edit">
								<a href="#"
									class="js-edit-room"
									data-id="<?php echo $row_id; ?>"
									data-name="<?php echo $row_name; ?>"
									data-subjects="<?php echo $row_subjects; ?>">
									Изменить
								</a>
							</span> |
							<span class="trash">
								<a href="#"
									class="js-delete-room"
									data-id="<?php echo $row_id; ?>"
									data-name="<?php echo $row_name; ?>">
									Удалить
								</a>
							</span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>

			<!-- Футер с плюсиком «Добавить кабинет» (по образцу периодов) -->
			<tfoot>
			<tr class="fs-add-row-tr">
				<td colspan="3">
					<button type="button"
							class="button-link scss-add-item js-add-room"
							title="Добавить кабинет">
						<span class="dashicons dashicons-plus"></span>
					</button>
				</td>
			</tr>
			</tfoot>
		</table>

	<?php endif; ?>

</div>
