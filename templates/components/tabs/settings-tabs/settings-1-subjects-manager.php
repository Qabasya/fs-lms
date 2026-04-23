<?php
require_once FS_LMS_PATH . 'templates/ui_renderers.php';
$active_tab = 'tab-1';

if ( isset( $_GET['tab'] ) ) {
	$active_tab = sanitize_text_field( $_GET['tab'] );
}

?>

<div id="tab-1" class="tab-pane <?php echo $active_tab === 'tab-1' ? 'active' : ''; ?>">

	<h1 class="wp-heading-inline">Активные предметы</h1>

	<a class="page-title-action" id="fs-import-trigger">Импортировать предмет</a>
	<input type="file" id="fs-import-file" accept=".json" style="display:none;">

	<?php settings_errors(); ?>

	<?php if ( empty( $subjects ) ) : ?>

		<div class="notice notice-info inline" style="margin:20px 0 0 0;">
			<p>Вы еще не создали ни одного предмета.</p>
			<button type="button"
					class="page-title-action js-add-subject"
					title="Добавить первый предмет">
				Добавить первый предмет
			</button>
		</div>

	<?php else : ?>

		<table class="wp-list-table widefat fixed striped fs-table fs-table--boilerplate">

			<thead>
			<tr>
				<th class="manage-column column-title column-primary" style="width: 40%;">Название предмета</th>
				<th class="manage-column column-title column-primary" style="width: 40%;">ID предмета</th>
				<th class="manage-column column-title column-primary" style="width: 20%;">Действия</th>
			</tr>
			</thead>

			<tbody id="the-list">
			<?php foreach ( $subjects as $subject ) : ?>
				<tr id="subject-row-<?php echo esc_attr( $subject->key ); ?>">
					<td class="column-title">
						<strong>
							<a class="row-title" href="?page=fs_subject_<?php echo esc_attr( $subject->key ); ?>">
								<?php echo esc_html( $subject->name ); ?>
							</a>
						</strong>
					</td>

					<td>
						<?php render_fs_badge( $subject->key, 'gray' ); ?>
					</td>

					<td class="column-actions">
						<div class="row-actions visible">
		<span class="edit">
			<a href="#"
				class="open-quick-edit"
				data-key="<?php echo esc_attr( $subject->key ); ?>"
				data-name="<?php echo esc_attr( $subject->name ); ?>">
				Изменить
			</a>
		</span> |
							<span class="export">
			<a href="#"
				class="js-export-subject"
				data-key="<?php echo esc_attr( $subject->key ); ?>">
				Экспорт
			</a>
		</span> |
							<span class="trash">
			<a href="#"
				class="delete-subject"
				data-key="<?php echo esc_attr( $subject->key ); ?>">
				Удалить
			</a>
		</span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>

			<tfoot>
			<tr class="fs-add-row-tr">
				<td colspan="3">
					<button type="button"
							class="button-link scss-add-item js-add-subject"
							title="Добавить новый предмет">
						<span class="dashicons dashicons-plus"></span>
					</button>
				</td>
			</tr>
			</tfoot>
		</table>

	<?php endif; ?>


	<table style="display:none;">
		<tr id="fs-quick-edit-row" class="inline-edit-row" style="display:none;">
			<td colspan="4" class="colspanchange">

				<form id="fs-quick-edit-form">

					<fieldset class="inline-edit-col-left">

						<legend class="inline-edit-legend">
							Быстрое редактирование
						</legend>

						<div class="inline-edit-col">

							<label>
								<span class="title">Название</span>
								<span class="input-text-wrap">
									<input type="text" name="name" value="">
								</span>
							</label>

							<input type="hidden" name="key" value="">

							<?php

							use Inc\Enums\Nonce;

							wp_nonce_field( Nonce::Subject->value, 'security' );
							?>

						</div>

					</fieldset>

					<p class="submit inline-edit-save">

						<button type="button" class="button cancel alignleft">
							Отмена
						</button>

						<button type="submit" class="button button-primary save alignright">
							Обновить
						</button>

						<span class="spinner"></span>

						<br class="clear">

					</p>

				</form>

			</td>
		</tr>
	</table>

</div>
