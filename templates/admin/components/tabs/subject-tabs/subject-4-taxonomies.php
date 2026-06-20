<?php

declare( strict_types=1 );

use Inc\Enums\Subject\TaxonomyDisplayType;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Subject\SubjectViewDTO $dto
 */

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
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
			<th class=" column-primary tw-30">Название</th>
			<th class="">Тип отображения</th>
			<th class="">Обязательна</th>
			<th class="">Действия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php foreach ( $dto->taxonomies as $tax ) : ?>
			<?php
			$is_protected = ( $tax->slug === $dto->protected_tax );
			$display_type = $tax->display_type ?? 'select';
			$display_text = TaxonomyDisplayType::tryFrom( $display_type )?->label() ?? $display_type;
			$has_no_terms = $tax->is_required && ( (int) wp_count_terms( array( 'taxonomy' => $tax->slug, 'hide_empty' => false ) ) === 0 );
			?>
			<tr data-slug="<?php echo esc_attr( $tax->slug ); ?>"
				data-name="<?php echo esc_attr( $tax->name ); ?>"
				data-display="<?php echo esc_attr( $display_type ); ?>"
				data-required="<?php echo esc_attr( $tax->is_required ? '1' : '0' ); ?>">

				<td class="column-title">
					<strong><?php echo esc_html( $tax->name ); ?></strong>
					<?php if ( $is_protected ) : ?>
						<span class="dashicons dashicons-lock fs-dashicon fs-dashicon--muted" title="Системная таксономия"></span>
					<?php endif; ?>
					<?php if ( $has_no_terms ) : ?>
						<span class="dashicons dashicons-warning fs-dashicon fs-dashicon--danger" title="Нет термов — задания нельзя публиковать"></span>
					<?php endif; ?>
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
			<td colspan="4">
				<button type="button" class="button-link scss-add-item js-add-taxonomy" title="Добавить таксономию">
					<span class="dashicons dashicons-plus"></span>
				</button>
			</td>
		</tr>
		</tfoot>
	</table>
</div>