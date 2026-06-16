<?php
/**
 * Модальное окно выбора существующего родителя из базы данных.
 * Открывается из userlist-1-applications.php по клику на .js-select-existing-parent.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-select-parent-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Назначить родителя', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<input type="hidden" id="spm-application-id" value="">

			<p class="description fs-mb-md">
				<?php esc_html_e( 'Найдите существующего родителя по имени или email.', 'fs-lms' ); ?>
			</p>

			<div class="fs-form-group">
				<label for="spm-search"><?php esc_html_e( 'Поиск', 'fs-lms' ); ?></label>
				<input type="text"
					id="spm-search"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Начните вводить имя для поиска…', 'fs-lms' ); ?>">
			</div>

			<div id="spm-results" class="fs-mt-md">
				<table class="wp-list-table widefat fixed striped fs-table" id="spm-table" hidden>
					<thead>
						<tr>
							<th><?php esc_html_e( 'ФИО', 'fs-lms' ); ?></th>
							<th><?php esc_html_e( 'Email', 'fs-lms' ); ?></th>
							<th><?php esc_html_e( 'Выбрать', 'fs-lms' ); ?></th>
						</tr>
					</thead>
					<tbody id="spm-tbody"></tbody>
				</table>
				<p id="spm-no-results" class="description" hidden>
					<?php esc_html_e( 'Ничего не найдено.', 'fs-lms' ); ?>
				</p>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel">
				<?php esc_html_e( 'Отмена', 'fs-lms' ); ?>
			</button>
		</div>
	</div>
</div>
