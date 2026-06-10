<?php
/**
 * Модальное окно выбора режима восстановления из архива.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<div id="fs-restore-archive-modal" class="fs-lms-modal hidden" aria-hidden="true">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-sm">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">
				<?php esc_html_e( 'Вернуть в заявки', 'fs-lms' ); ?>
			</h2>
			<button type="button"
				class="fs-lms-modal-close fs-close js-modal-close"
				aria-label="<?php esc_attr_e( 'Закрыть', 'fs-lms' ); ?>">
				&times;
			</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-form-group">
				<label class="fs-radio-label">
					<input type="radio" name="ram-mode" value="0" checked>
					<span><?php esc_html_e( 'Только данные ученика — родитель заполнит сам', 'fs-lms' ); ?></span>
				</label>
				<label class="fs-radio-label" id="ram-with-parent-label">
					<input type="radio" name="ram-mode" value="1" id="ram-mode-with-parent">
					<span id="ram-with-parent-text"><?php esc_html_e( 'С данными родителя — заявка сразу готова к проверке', 'fs-lms' ); ?></span>
				</label>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel">
				<?php esc_html_e( 'Отмена', 'fs-lms' ); ?>
			</button>
			<button type="button" class="button button-primary" id="ram-confirm-btn">
				<?php esc_html_e( 'Вернуть', 'fs-lms' ); ?>
			</button>
		</div>
	</div>
</div>
