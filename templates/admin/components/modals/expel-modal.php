<?php
/**
 * Модал подтверждения отчисления студента.
 * Рендерится через ExpulsionController::renderModal() в admin_footer.
 *
 * @var string $nonce Nonce для действия отчисления
 */
?>

<div id="fs-expel-modal" class="fs-lms-modal hidden" aria-modal="true" role="dialog">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-sm">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Отчислить студента</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<p class="fs-expel-warning">
				<span class="dashicons dashicons-warning"></span>
				Будут удалены профили студента и родителя. Данные сохранятся в архиве.
			</p>

			<p class="fs-expel-student-name"></p>

			<form id="fs-expel-form" autocomplete="off">
				<input type="hidden" name="student_id" value="">

				<div class="fs-form-group">
					<label for="expel-reason">Причина отчисления</label>
					<textarea id="expel-reason" name="reason" rows="3" placeholder="Укажите причину..."></textarea>
				</div>
			</form>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button fs-lms-modal-cancel js-modal-close">Отмена</button>
			<button type="submit" form="fs-expel-form" class="button button-link-delete js-expel-confirm">
				Отчислить
			</button>
		</div>
	</div>
</div>
