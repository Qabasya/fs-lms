<?php

declare( strict_types=1 );

/**
 * Общее окно множественного выбора из каталога: источник (радио), предмет (список), поиск и
 * список с чекбоксами. Заголовок, подписи и состав источников и предметов задаёт вызывающий код
 * (`admin/modals/bulk-pick-modal.js`). Выводится в футере экранов, где есть выбор из каталога
 * (AdminFooterModalsController).
 *
 * @package Inc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="fs-lms-bulk-pick-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title"><?php esc_html_e( 'Выбор', 'fs-lms' ); ?></h2>
			<button type="button" class="fs-lms-modal-close" aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">&times;</button>
		</div>

		<div class="fs-lms-modal-body fs-bulk-pick">
			<div class="fs-bulk-pick__bar">
				<div class="fs-bulk-pick__sources" data-bulk-sources role="radiogroup" aria-label="<?php esc_attr_e( 'Источник', 'fs-lms' ); ?>" hidden></div>
				<select class="fs-bulk-pick__subject" data-bulk-subject aria-label="<?php esc_attr_e( 'Предмет', 'fs-lms' ); ?>" hidden></select>
				<input type="search" class="fs-bulk-pick__search" data-bulk-search placeholder="<?php esc_attr_e( 'Поиск по названию…', 'fs-lms' ); ?>">
			</div>

			<div class="fs-bulk-pick__list" data-bulk-list></div>
		</div>

		<div class="fs-lms-modal-footer">
			<span class="fs-bulk-pick__count" data-bulk-count></span>
			<div class="fs-bulk-pick__actions">
				<button type="button" class="fs-lms-modal-cancel button"><?php esc_html_e( 'Отмена', 'fs-lms' ); ?></button>
				<button type="button" class="fs-lms-modal-confirm button button-primary" disabled><?php esc_html_e( 'Добавить', 'fs-lms' ); ?></button>
			</div>
		</div>
	</div>
</div>
