<div id="fs-subject-modal" class="fs-lms-modal hidden">
	<!-- Затемнение (прозрачное по умолчанию, можно включить при необходимости) -->
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<!-- Шапка в стиле WPBakery -->
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Создать новый предмет</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<!-- Тело модалки -->
		<div class="fs-lms-modal-body">
			<form id="fs-add-subject-form" autocomplete="off">
				<div class="fs-form-group">
					<label for="subj_name">Название (например: Информатика ЕГЭ)</label>
					<input type="text" id="subj_name" name="name" placeholder="Введите название..." required>
				</div>

				<div class="fs-form-group">
					<label for="subj_key">Технический ключ (например: inf_ege)</label>
					<input type="text" id="subj_key" name="key" placeholder="Только латиница и подчеркивания..." required
							pattern="[a-z0-9_]+">
					<p class="description">Используется для названия типов записей в базе.</p>
				</div>

				<div class="fs-form-group">
					<label for="subj_tasks_count">Количество типов заданий (от 1 до 100)</label>
					<input type="number" id="subj_tasks_count" name="tasks_count"
							min="1" max="100" value="1" required>
					<p class="description">Сколько уникальных номеров заданий будет в курсе.</p>
				</div>

				<?php
				use Inc\Enums\Nonce;
				wp_nonce_field( Nonce::Subject->value, 'security' );
				?>

				<!-- Футер с кнопками -->
				<div class="fs-lms-modal-footer">
					<button type="button" class="button fs-lms-modal-cancel js-modal-close">Отмена</button>
					<button type="submit" class="button button-primary">Создать предмет и CPT</button>
				</div>
			</form>
		</div>
	</div>
</div>