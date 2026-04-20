<?php use Inc\Enums\Nonce; ?>

<div id="fs-task-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<!-- Шапка -->
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Создание нового задания</h2>
			<!-- Кнопка закрытия: button вместо span для доступности, классы сохранены для вашего JS -->
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<!-- Тело модалки -->
		<div class="fs-lms-modal-body">
			<form id="fs-task-creation-form">
				<?php wp_nonce_field( Nonce::TaskCreation->value, 'nonce' ); ?>

				<input type="hidden" name="subject_key" id="fs-modal-subject"
						value="<?php echo esc_attr( $subject_key ?? '' ); ?>">

				<div class="fs-form-group">
					<label for="fs-modal-term">Номер задания:</label>
					<select id="fs-modal-term" name="term_id" required disabled>
						<option value="">Загрузка типов...</option>
					</select>
				</div>

				<div class="fs-form-group">
					<label for="fs-modal-boilerplate">Типовое условие (Шаблон):</label>
					<select id="fs-modal-boilerplate" name="boilerplate_uid" disabled>
						<option value="">-- Сначала выберите номер --</option>
					</select>
					<p class="description">Выберите номер, чтобы подгрузить доступные условия.</p>
				</div>

				<div class="fs-form-group">
					<label for="fs-modal-title">Название задания:</label>
					<input type="text" name="task_title" id="fs-modal-title"
							placeholder="Например: Вариант СтатГрад №1" required>
				</div>

				<!-- Футер с кнопками -->
				<div class="fs-modal-footer">
					<button type="button" class="button fs-lms-modal-cancel js-modal-close">Отмена</button>
					<button type="submit" class="button button-primary" id="fs-modal-submit">Продолжить</button>
				</div>
			</form>
		</div>
	</div>
</div>