<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
?>
<div id="fs-taxonomy-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<!-- Шапка -->
		<div class="fs-lms-modal-header">
			<h2 id="modal-title" class="fs-lms-modal-title">Новая таксономия</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<!-- Тело -->
		<div class="fs-lms-modal-body">
			<input type="hidden" id="tax-subject-key" value="<?php echo esc_attr( $dto->subject_key ); ?>">
			<input type="hidden" id="tax-action" value="store">
			<input type="hidden" id="tax-original-slug" value="">

			<div class="fs-form-group">
				<label for="tax-name">Название:</label>
				<input type="text" id="tax-name" placeholder="Введите название...">
			</div>

			<div class="fs-form-group" id="slug-container">
				<label for="tax-slug">Ярлык:</label>
				<div style="display: flex; align-items: stretch;">
					<span id="tax-slug-prefix" style="background: #f0f0f1; padding: 0 10px; border: 1px solid #8c8f94; border-right: none; border-radius: 4px 0 0 4px; font-family: monospace; line-height: 35px; display: flex; align-items: center; white-space: nowrap;">
						<?php echo esc_html( $dto->subject_key ); ?>_
					</span>
					<input type="text" id="tax-slug" style="border-radius: 0 4px 4px 0; flex: 1;">
				</div>
			</div>

			<div class="fs-form-group">
				<label>
					<input type="checkbox" id="tax-is-required" value="1">
					Сделать обязательной
				</label>
			</div>

			<div class="fs-form-group">
				<label>Тип отображения:</label>
				<div class="fs-radio-group">
					<label class="fs-radio-label">
						<input type="radio" name="tax_display_type" value="select" checked>
						<span>Выпадающий список</span>
					</label>
					<label class="fs-radio-label">
						<input type="radio" name="tax_display_type" value="radio">
						<span>Один выбор</span>
					</label>
					<label class="fs-radio-label">
						<input type="radio" name="tax_display_type" value="checkbox">
						<span>Множественный выбор</span>
					</label>
				</div>
			</div>
		</div>

		<!-- Футер -->
		<div class="fs-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel js-modal-close">Отмена</button>
			<button type="button" class="button button-primary js-modal-save">Сохранить</button>
		</div>
	</div>
</div>