<?php

declare( strict_types=1 );

/**
 * @var array $academic_periods Массив учебных периодов из репозитория
 */
require_once FS_LMS_PATH . 'templates/admin/ui_renderers.php';
?>

<div id="tab-academic-periods" class="tab-pane active">

	<div class="header-row">
		<h1 class="wp-heading-inline">Учебные периоды</h1>
		<!-- Кнопка из шапки удалена по стандарту Subjects -->
	</div>

	<?php settings_errors(); ?>

	<?php if ( empty( $academic_periods ) ) : ?>

		<div class="notice notice-info inline" style="margin:20px 0 0 0;">
			<p>Вы еще не создали ни одного учебного периода.</p>
			<button type="button" class="page-title-action js-add-period">
				Добавить первый период
			</button>
		</div>

	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table fs-table--boilerplate">
			<thead>
			<tr>
				<th class="manage-column column-title column-primary tw-30">Название периода</th>
				<th class="manage-column column-title column-primary">ID (Ключ)</th>
				<th class="manage-column column-title column-primary">Сроки проведения</th>
				<th class="manage-column column-title column-primary">Статус</th>
				<th class="manage-column column-title column-primary">Действия</th>
			</tr>
			</thead>

			<tbody id="the-list">
			<?php foreach ( $academic_periods as $period ) : ?>
				<?php
				$is_current = (bool) ( $period['is_current'] ?? false );
				$row_id     = esc_attr( $period['id'] );
				$row_name   = esc_attr( $period['name'] );
				$start_date = ! empty( $period['start_date'] ) ? trim( (string) $period['start_date'] ) : '';
				$end_date   = ! empty( $period['end_date'] ) ? trim( (string) $period['end_date'] ) : '';

				// Безопасное форматирование даты через встроенные механизмы WP
				$start_display = ! empty( $start_date ) && strtotime( $start_date ) ? wp_date( 'd.m.Y', strtotime( $start_date ) ) : '—';
				$end_display   = ! empty( $end_date ) && strtotime( $end_date ) ? wp_date( 'd.m.Y', strtotime( $end_date ) ) : '—';
				?>
				<tr id="period-row-<?php echo $row_id; ?>">
					<td class="column-title">
						<strong>
							<a class="row-title js-edit-period"
								href="#"
								data-id="<?php echo $row_id; ?>"
								data-name="<?php echo $row_name; ?>"
								data-start-date="<?php echo esc_attr( $start_date ); ?>"
								data-end-date="<?php echo esc_attr( $end_date ); ?>"
								data-current="<?php echo $is_current ? '1' : '0'; ?>">
								<?php echo esc_html( $period['name'] ); ?>
							</a>
						</strong>
					</td>

					<td>
						<?php render_fs_badge( $period['id'], 'gray' ); ?>
					</td>

					<td>
						<span class="dashicons dashicons-calendar-alt" style="font-size:16px; vertical-align:middle; margin-right:3px; color:#646970;"></span>
						<code><?php echo esc_html( "{$start_display} — {$end_display}" ); ?></code>
					</td>

					<td>
						<?php
						if ( true === $is_current ) {
							render_fs_badge( 'Текущий', 'green' );
						} else {
							render_fs_badge( 'Архив', 'blue' );
						}
						?>
					</td>

					<td class="column-actions">
						<div class="row-actions visible">
					<span class="edit">
						<a href="#"
							class="js-edit-period"
							data-id="<?php echo $row_id; ?>"
							data-name="<?php echo $row_name; ?>"
							data-start-date="<?php echo esc_attr( $start_date ); ?>"
							data-end-date="<?php echo esc_attr( $end_date ); ?>"
							data-current="<?php echo $is_current ? '1' : '0'; ?>">
							Изменить
						</a>
					</span> |
							<span class="trash">
						<a href="#"
							class="js-delete-period"
							data-key="<?php echo $row_id; ?>"
							data-name="<?php echo $row_name; ?>">
							Удалить
						</a>
					</span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>

			<!-- Добавлен футер с плюсиком "Добавить новый период" по образцу Subjects -->
			<tfoot>
			<tr class="fs-add-row-tr">
				<td colspan="5">
					<button type="button"
							class="button-link scss-add-item js-add-period"
							title="Добавить новый период">
						<span class="dashicons dashicons-plus"></span>
					</button>
				</td>
			</tr>
			</tfoot>
		</table>

	<?php endif; ?>

</div>