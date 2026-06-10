<?php
/** @var \Inc\DTO\Subject\SubjectViewDTO $dto */
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
		<form id="fs-taxonomy-form" novalidate>
		<div class="fs-lms-modal-body">
			<input type="hidden" id="tax-subject-key" value="<?php echo esc_attr( $dto->subject_key ); ?>">
			<input type="hidden" id="tax-action" value="store">
			<input type="hidden" id="tax-original-slug" value="">

			<div class="fs-form-group">
				<label for="tax-name">Название:</label>
				<input
                        type="text"
                        id="tax-name"
                        placeholder="Введите название..."
                        data-validate="cyrillicDigits"
                        required
                >
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

		</form>

		<!-- Футер -->
		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel js-modal-close">Отмена</button>
			<button type="button" class="button button-primary js-modal-save">Сохранить</button>
		</div>
	</div>
</div>