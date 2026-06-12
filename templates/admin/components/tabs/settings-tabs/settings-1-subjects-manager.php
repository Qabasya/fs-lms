<?php
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\PostTypeResolver;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$groupsRepo = new GroupsRepository();
?>

<div id="tab-1" class="tab-pane active">

	<div class="header-row">
		<h1 class="wp-heading-inline">Активные предметы</h1>


		<div class="description-actions">

			<a class="page-title-action" id="fs-import-trigger">Импортировать предмет</a>
			<input type="file" id="fs-import-file" accept=".json" class="hidden">

		</div>
	</div>

		<?php settings_errors(); ?>

		<?php if ( empty( $subjects ) ) : ?>

			<div class="notice notice-info inline fs-table__no-items">
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
					<th class="column-title">Название предмета</th>
					<th class="column-title column-primary tw-15">ID предмета</th>
					<th class="column-title tw-10">Заданий</th>
					<th class="column-title tw-10">Статей</th>
					<th class="column-title tw-10">Групп</th>
					<th class="column-title column-primary tw-15">Действия</th>
				</tr>
				</thead>

				<tbody id="the-list">
				<?php foreach ( $subjects as $subject ) :
					$tasks_cpt    = PostTypeResolver::tasks( $subject->key );
					$articles_cpt = PostTypeResolver::articles( $subject->key );

					$tasks_counts    = wp_count_posts( $tasks_cpt );
					$articles_counts = wp_count_posts( $articles_cpt );

					$tasks_total    = array_sum( (array) $tasks_counts ) - ( $tasks_counts->trash ?? 0 );
					$articles_total = array_sum( (array) $articles_counts ) - ( $articles_counts->trash ?? 0 );

					$groups_total = count( $groupsRepo->findBySubjectKey( $subject->key ) );
				?>
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

						<td><?php echo esc_html( (string) $tasks_total ); ?></td>
						<td><?php echo esc_html( (string) $articles_total ); ?></td>
						<td><?php echo esc_html( (string) $groups_total ); ?></td>

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
					<td colspan="6">
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


		<table class="hidden">
			<tr id="fs-quick-edit-row" class="inline-edit-row">
				<td colspan="6" class="colspanchange">

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
<?php require_once FS_LMS_PATH . 'templates/admin/components/modals/confirm-modal.php'; ?>