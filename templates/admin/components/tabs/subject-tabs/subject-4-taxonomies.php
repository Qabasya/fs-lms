<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
require_once FS_LMS_PATH . 'templates/admin/ui_renderers.php';

// 1. Выносим словарь за пределы цикла, чтобы не нагружать память
$display_labels = array(
	'select'   => 'Выпадающий список',
	'radio'    => 'Один выбор',
	'checkbox' => 'Чекбокс',
);
?>

<div class="taxonomy-manager-wrapper">
	<h1 class="wp-heading-inline">Таксономии предмета</h1>
	<p class="description">Здесь задаются таксономии для выбранного предмета.
		<br>Для добавления новой таксономии нажмите на символ «+» внизу таблицы.
		<br>Обязательные таксономии используются для фильтрации на странице отображения задания
	</p>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--taxonomy js-taxonomy-table">
		<thead>
		<tr>
			<th class="manage-column column-primary tw-30">Название</th>
			<th class="manage-column" >Ярлык (Slug)</th>
			<th class="manage-column">Тип отображения</th>
			<th class="manage-column">Обязательна</th>
			<th class="manage-column">Действия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php foreach ( $dto->taxonomies as $tax ) : ?>
			<?php
			$is_protected = ( $tax->slug === $dto->protected_tax );
			$display_type = $tax->display_type ?? 'select';
			// Получаем красивое название из словаря
			$display_text = $display_labels[ $display_type ] ?? $display_type;
			?>
			<tr data-slug="<?php echo esc_attr( $tax->slug ); ?>"
				data-name="<?php echo esc_attr( $tax->name ); ?>"
				data-display="<?php echo esc_attr( $display_type ); ?>"
				data-required="<?php echo esc_attr( $tax->is_required ? '1' : '0' ); ?>">

				<td class="column-title">
					<strong><?php echo esc_html( $tax->name ); ?></strong>
					<?php if ( $is_protected ) : ?>
						<span class="dashicons dashicons-lock" title="Системная таксономия"
								style="font-size: 16px; color: #8c8f94; vertical-align: text-bottom;"></span>
					<?php endif; ?>
				</td>

				<td>
					<?php render_fs_badge( $tax->slug, 'blue' ); ?>
				</td>

				<td>
					<?php render_fs_badge( $display_text, 'gray' ); ?>
				</td>

				<td>
					<?php
					render_fs_toggle(
						"is_required_status_{$tax->slug}",
						$tax->is_required,
						array(
							'readonly' => true, // Только отображение
							'id'       => 'view_tax_' . $tax->slug,
						)
					);
					?>
				</td>

				<td class="column-actions">
					<div class="row-actions visible">
				<span class="edit">
					<a href="<?php echo esc_url( admin_url( "edit-tags.php?taxonomy={$tax->slug}" ) ); ?>">Настроить</a>
				</span>
						<?php if ( ! $is_protected ) : ?>
							<span class="inline-edit"> | <a href="#" class="js-edit-tax">Изменить</a></span>
							<span class="trash"> | <a href="#" class="js-delete-tax">Удалить</a></span>
						<?php endif; ?>
					</div>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>

		<tfoot>
		<tr class="fs-add-row-tr">
			<td colspan="5">
				<button type="button" class="button-link scss-add-item js-add-taxonomy" title="Добавить таксономию">
					<span class="dashicons dashicons-plus"></span>
				</button>
			</td>
		</tr>
		</tfoot>
	</table>
</div>