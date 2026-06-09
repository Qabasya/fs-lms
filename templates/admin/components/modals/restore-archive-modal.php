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
				<label for="ram-mode-select">
					<?php esc_html_e( 'Режим восстановления', 'fs-lms' ); ?>
				</label>
				<select id="ram-mode-select" class="widefat">
					<option value="0">
						<?php esc_html_e( 'Только данные ученика — ожидание данных родителя', 'fs-lms' ); ?>
					</option>
					<option value="1" id="ram-mode-with-parent">
						<?php esc_html_e( 'С данными родителя — заявка сразу готова к проверке', 'fs-lms' ); ?>
					</option>
				</select>
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
