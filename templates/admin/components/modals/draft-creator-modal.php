<div id="fs-lms-draft-creator-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-sm">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title" id="fs-lms-draft-creator-title">Создать</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-form-group">
				<label for="fs-lms-draft-title">Название</label>
				<input type="text" id="fs-lms-draft-title" class="regular-text" placeholder="Название...">
			</div>

			<div class="fs-form-group fs-lms-draft-work-type-row" hidden>
				<label for="fs-lms-draft-work-type">Тип работы</label>
				<select id="fs-lms-draft-work-type" class="postform">
					<option value="practice">Практическая</option>
					<option value="independent">Самостоятельная</option>
					<option value="homework">Домашняя работа</option>
				</select>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button js-modal-close">Отмена</button>
			<button type="button" id="fs-lms-draft-submit" class="button button-primary">Создать</button>
		</div>
	</div>
</div>
