<?php defined( 'ABSPATH' ) || exit; ?>

<div id="consent-definition-modal" class="fs-modal" style="display:none;" role="dialog" aria-modal="true">
	<div class="fs-modal__overlay js-close-consent-modal"></div>

	<div class="fs-modal__container" style="max-width:480px;">
		<div class="fs-modal__header">
			<h2 class="fs-modal__title">Добавить согласие</h2>
			<button type="button" class="fs-modal__close js-close-consent-modal" aria-label="Закрыть">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>

		<div class="fs-modal__body">
			<div id="js-consent-modal-notice" style="display:none;" class="fs-modal-error"></div>

			<div class="fs-form-group">
				<label for="consent-def-name">Название <span style="color:#d63638">*</span></label>
				<input type="text" id="consent-def-name" class="regular-text"
					placeholder="Согласие на обработку персональных данных"
					autocomplete="off">
			</div>

			<div class="fs-form-group">
				<label for="consent-def-key">Ключ <span style="color:#d63638">*</span></label>
				<input type="text" id="consent-def-key" class="regular-text"
					placeholder="pd_processing"
					autocomplete="off"
					pattern="[a-z0-9_\-]+"
					title="Только строчные латинские буквы, цифры, _ и -">
				<p class="description">Уникальный идентификатор. Для подписания в форме заявки используйте ключ <code>pd_processing</code>.</p>
			</div>
		</div>

		<div class="fs-modal__footer">
			<button type="button" class="button js-close-consent-modal">Отмена</button>
			<button type="button" class="button button-primary" id="js-consent-def-submit">
				Создать согласие
			</button>
		</div>
	</div>
</div>
