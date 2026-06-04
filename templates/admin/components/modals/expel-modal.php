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

                    <select id="expel-reason" name="reason">
                        <option value="">Выберите причину</option>
                        <option value="Окончание курса">Окончание курса</option>
                        <option value="Перевод">Перевод</option>
                        <option value="По собственному желанию">По собственному желанию</option>
                        <option value="Другое">Другое</option>
                    </select>
                </div>

                <div class="fs-form-group" id="fs-expel-custom-reason-wrap" hidden>
                    <label for="expel-custom-reason">Уточните причину</label>
                    <textarea
                            id="expel-custom-reason"
                            name="custom_reason"
                            rows="3"
                            placeholder="Укажите причину..."
                    ></textarea>
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
