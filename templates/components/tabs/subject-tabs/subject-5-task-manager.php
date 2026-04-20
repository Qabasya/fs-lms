<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
?>

<div class="task-manager-wrapper">
	<h1 class="wp-heading-inline">Менеджер шаблонов</h1>
	<p class="description">Управление визуальными шаблонами заданий</p>

	<table class="wp-list-table widefat fixed striped js-task-manager-table"
			data-subject="<?php echo esc_attr( $dto->subject_key ); ?>">
		<thead>
		<tr>
			<th class="manage-column column-primary column-task-name">Номер задания</th>
			<th class="manage-column column-template-select">Визуальный шаблон</th>
			<th class="manage-column column-actions">Типовые условия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( ! empty( $dto->task_types ) ) : ?>
			<?php foreach ( $dto->task_types as $type ) : ?>
				<tr data-term-id="<?php echo $type->id; ?>"
					data-task-slug="<?php echo esc_attr( $type->slug ); ?>">

					<td class="column-title has-row-actions">
						<strong><?php echo esc_html( $type->description ); ?></strong>
						<div class="row-actions visible">
							<span class="view">Slug: <code><?php echo esc_attr( $type->slug ); ?></code></span>
						</div>
					</td>

					<td class="column-template-select">
						<select class="js-change-term-template" <?php disabled( $type->post_count > 0 ); ?>>
							<?php foreach ( $dto->all_templates as $tpl ) : ?>
								<option value="<?php echo esc_attr( $tpl->id ); ?>"
										<?php selected( $type->getTemplateId(), (string) $tpl->id ); ?>>
									<?php echo esc_html( $tpl->title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>

					<td class="column-actions">
						<a href="<?php echo admin_url( 'admin.php?page=fs_boilerplate_manager&subject=' . esc_attr( $dto->subject_key ) . '&term=' . esc_attr( $type->slug ) ); ?>"
							class="button button-secondary">Настроить</a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr class="no-items"><td colspan="3">Задания не найдены.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>
</div>