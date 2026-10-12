<?php
/**
 * Модалка «Нашли ошибку?» — одна на страницу задания или статьи.
 * Поведение — components/report-error.js, отправка — AjaxHook::ReportContentError.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="fs-report-modal js-report-modal" role="dialog" aria-modal="true" aria-labelledby="fs-report-title" hidden>
	<div class="fs-report-modal__backdrop js-report-close"></div>
	<form class="fs-report-modal__box js-report-form">
		<h2 class="fs-report-modal__title" id="fs-report-title">Нашли ошибку?</h2>
		<textarea class="fs-report-modal__field js-report-text" name="message" rows="6" maxlength="2000" required
			placeholder="Пожалуйста, опишите найденную ошибку"></textarea>
		<p class="fs-report-modal__status js-report-status" role="status" hidden></p>
		<div class="fs-report-modal__actions">
			<button type="button" class="fs-report-modal__cancel js-report-close">Отмена</button>
			<button type="submit" class="fs-report-modal__send js-report-send">Отправить</button>
		</div>
	</form>
</div>
