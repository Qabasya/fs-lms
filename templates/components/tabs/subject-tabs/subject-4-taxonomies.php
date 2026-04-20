<?php /** @var \Inc\DTO\SubjectViewDTO $dto */ ?>

<div class="taxonomy-manager-wrapper">
	<h1 class="wp-heading-inline">Дополнительные таксономии</h1>
	<p class="description">Управление разделами, темами и авторами.</p>

	<table class="wp-list-table widefat fixed striped js-taxonomy-table">
		<thead>
		<tr>
			<th class="manage-column column-primary">Название</th>
			<th class="manage-column">Ярлык (Slug)</th>
			<th class="manage-column">Действия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php foreach ( $dto->taxonomies as $tax ) : ?>
			<?php $is_protected = ( $tax->slug === $dto->protected_tax ); ?>
			<tr data-slug="<?php echo esc_attr( $tax->slug ); ?>"
				data-name="<?php echo esc_attr( $tax->name ); ?>"
				data-display="<?php echo esc_attr( $tax->display_type ?? 'select' ); ?>">
				<td class="column-title">
					<strong><?php echo esc_html( $tax->name ); ?></strong>
					<?php if ( $is_protected ) : ?>
						<span class="dashicons dashicons-lock" title="Системная таксономия"></span>
					<?php endif; ?>
				</td>
				<td><code><?php echo esc_html( $tax->slug ); ?></code></td>
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
			<td colspan="3">
				<button type="button" class="button-link scss-add-item js-add-taxonomy" title="Добавить таксономию">
					<span class="dashicons dashicons-plus"></span>
				</button>
			</td>
		</tr>
		</tfoot>
	</table>
</div>
