<?php
/**
 * @var string                               $subject              Ключ предмета
 * @var string                               $term                 Слаг типа задания
 * @var \Inc\DTO\TaskTypeBoilerplateDTO[]     $boilerplates         Список boilerplate
 * @var string                               $display_name         Отображаемое имя типа задания
 * @var string                               $subject_display_name Отображаемое имя предмета
 * @var string                               $back_url             URL кнопки «Назад»
 */

use Inc\Enums\Nonce;
?>

<div class="wrap boilerplate-manager-wrapper">
	<?php wp_nonce_field( Nonce::SaveBoilerplate->value, 'security' ); ?>

	<h1 class="wp-heading-inline">Типовые условия</h1>
	<p class="description"><?php echo esc_html( $display_name ); ?> / <?php echo esc_html( $subject_display_name ); ?></p>
	<hr class="wp-header-end">

	<table class="wp-list-table widefat fixed striped js-boilerplate-table">
		<thead>
		<tr>
			<th class="manage-column column-primary">Название шаблона</th>
			<th class="manage-column column-actions">Действия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $boilerplates ) ) : ?>
			<tr class="no-items">
				<td colspan="2">Для этого задания еще не создано типовых условий.</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $boilerplates as $bp ) : ?>
				<tr data-uid="<?php echo esc_attr( $bp->uid ); ?>">
					<td class="column-title">
						<strong>
							<a href="<?php echo esc_url( admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}" ) ); ?>">
								<?php echo esc_html( $bp->title ); ?>
							</a>
						</strong>
					</td>
					<td class="column-actions">
						<div class="row-actions visible">
							<span class="edit">
								<a href="<?php echo esc_url( admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}" ) ); ?>">Изменить</a>
							</span>
							<span class="trash"> |
								<a href="#" class="delete-boilerplate-link" data-uid="<?php echo esc_attr( $bp->uid ); ?>">Удалить</a>
							</span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>

		<tfoot>
		<tr class="fs-add-row-tr">
			<td colspan="2">
				<a href="<?php echo esc_url( admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=new" ) ); ?>"
					class="button-link js-add-boilerplate" title="Добавить новое условие">
					<span class="dashicons dashicons-plus"></span>
				</a>
			</td>
		</tr>
		</tfoot>
	</table>

	<p style="margin-top: 20px;">
		<a href="<?php echo esc_url( $back_url ); ?>" class="button">
			&larr; Назад в Менеджер заданий
		</a>
	</p>
</div>
