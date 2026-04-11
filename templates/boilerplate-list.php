<?php
/**
 * Список типовых условий для конкретного задания.
 * * @var string $subject
 * @var string $term
 * @var \Inc\DTO\TaskTypeBoilerplateDTO[] $boilerplates
 */
?>


<div class="wrap">
    <?php wp_nonce_field('save_boilerplate_nonce', 'fs_lms_boilerplate_nonce'); ?>
	<h1 class="wp-heading-inline">Типовые условия: <?php echo esc_html( $term ); ?></h1>

	<a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=new"); ?>" class="page-title-action">
		Добавить новое
	</a>

	<hr class="wp-header-end">

	<table class="wp-list-table widefat fixed striped">
		<thead>
		<tr>
			<th class="column-primary">Название шаблона</th>
			<th>Статус</th>
			<th>UID</th>
			<th style="width: 150px;">Действия</th>
		</tr>
		</thead>
		<tbody>
		<?php if ( empty( $boilerplates ) ) : ?>
			<tr>
				<td colspan="4">Для этого задания еще не создано типовых условий.</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $boilerplates as $bp ) : ?>
				<tr>
					<td class="column-primary">
						<strong>
							<a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}"); ?>">
								<?php echo esc_html( $bp->title ); ?>
							</a>
						</strong>
					</td>
					<td>
						<?php if ( $bp->is_default ) : ?>
							<span class="badge" style="background: #c6e1c6; color: #236b23; padding: 2px 8px; border-radius: 4px;">По умолчанию</span>
						<?php else : ?>
							<span style="color: #999;">—</span>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $bp->uid ); ?></code></td>
					<td>
						<a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}"); ?>">Изменить</a> |
						<a href="#" class="delete-boilerplate-link" style="color: #a00;" data-uid="<?php echo esc_attr($bp->uid); ?>">Удалить</a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<p>
		<a href="<?php echo admin_url("admin.php?page=fs_lms_settings"); ?>" class="button">&larr; Назад в настройки предметов</a>
	</p>
</div>