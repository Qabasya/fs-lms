<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
?>

<h3>Менеджер шаблонов</h3>
<table class="widefat fixed striped js-task-manager-table"
       data-subject="<?php echo esc_attr($dto->subject_key); ?>">
	<thead>
	<tr>
		<th class="column-task-name">Номер задания</th>
		<th class="column-template-select">Визуальный шаблон</th>
		<th class="column-actions">Типовые условия</th>
		<th class="status-cell"></th>
	</tr>
	</thead>
	<tbody>
	<?php if (!empty($dto->task_types)) : ?>
		<?php foreach ($dto->task_types as $type) : ?>
			<tr data-term-id="<?php echo $type->id; ?>"
			    data-task-slug="<?php echo esc_attr($type->slug); ?>">
				<td><strong><?php echo esc_html($type->description); ?></strong></td>
				<td>
					<select class="js-change-term-template"
						<?php disabled($type->post_count > 0); ?>>
						<?php foreach ($dto->all_templates as $tpl) : ?>
							<option value="<?php echo esc_attr($tpl->id); ?>"
								<?php selected($type->getTemplateId(), (string) $tpl->id); ?>>
								<?php echo esc_html($tpl->title); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
				<td>
					<a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=" . esc_attr($dto->subject_key) . "&term=" . esc_attr($type->slug)); ?>"
					   class="button">Настроить</a>
				</td>
				<td class="status-cell">
					<span class="spinner"></span>
					<span class="dashicons dashicons-yes js-success-icon" style="display:none; color:green;"></span>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>