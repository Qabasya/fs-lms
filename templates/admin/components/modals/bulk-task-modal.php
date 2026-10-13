<?php

declare( strict_types=1 );

/**
 * Окно «Массовое добавление заданий» конструктора работы: источник (приватный банк /
 * публичные задачи), поиск и список с чекбоксами. Выводится в футере экрана работы
 * (AdminFooterModalsController), поведение — `admin/modals/bulk-task-modal.js`.
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="fs-lms-bulk-task-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Массовое добавление заданий', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body fs-bulk-task">
			<div class="fs-bulk-task__bar">
				<div class="fs-bulk-task__sources" role="radiogroup" aria-label="<?php esc_attr_e( 'Откуда брать задания', 'fs-lms' ); ?>">
					<label class="fs-bulk-task__source">
						<input type="radio" name="fs_bulk_task_source" value="subject" checked>
						<?php esc_html_e( 'Приватный банк', 'fs-lms' ); ?>
					</label>
					<label class="fs-bulk-task__source">
						<input type="radio" name="fs_bulk_task_source" value="public">
						<?php esc_html_e( 'Публичные задачи', 'fs-lms' ); ?>
					</label>
				</div>
				<input type="search" class="fs-bulk-task__search" data-bulk-search placeholder="<?php esc_attr_e( 'Поиск по названию…', 'fs-lms' ); ?>">
			</div>

			<div class="fs-bulk-task__list" data-bulk-list></div>
		</div>

		<div class="fs-lms-modal-footer">
			<span class="fs-bulk-task__count" data-bulk-count></span>
			<div class="fs-bulk-task__actions">
				<button type="button" class="fs-lms-modal-cancel button"><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
				<button type="button" class="fs-lms-modal-confirm button button-primary" disabled><?php esc_html_e( 'Добавить в работу', 'fs-lms' ); ?></button>
			</div>
		</div>
	</div>
</div>
