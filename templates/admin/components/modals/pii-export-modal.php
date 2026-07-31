<?php
/**
 * Модалка подтверждения выгрузки персональных данных (A2).
 *
 * Файл экспорта учеников/родителей содержит ПД, а по явному запросу — ещё и
 * пароли учётных записей открытым текстом. Модалка проговаривает риск и делает
 * выгрузку паролей отдельным осознанным выбором (чекбокс снят по умолчанию).
 *
 * @package Inc\Templates
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div id="fs-lms-pii-export-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content fs-modal-md">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Экспорт персональных данных</h2>
			<button type="button" class="fs-lms-modal-close" aria-label="Закрыть">&times;</button>
		</div>
		<div class="fs-lms-modal-body">
			<p class="fs-lms-modal-message js-pii-export-summary"></p>

			<div class="notice notice-warning inline fs-pii-export__warning">
				<p>
					Файл содержит персональные данные. Храните и передавайте его только
					защищённым каналом и удаляйте сразу после использования.
					Ссылка на скачивание одноразовая: файл удаляется после первой загрузки.
				</p>
			</div>

			<label class="fs-pii-export__option">
				<input type="checkbox" class="js-pii-export-passwords">
				<span>Включить пароли учётных записей в файл</span>
			</label>

			<p class="fs-pii-export__hint">
				Без этой галочки в файл попадут только контактные и учебные данные —
				пароли не расшифровываются и в выгрузку не пишутся.
			</p>
		</div>
		<div class="fs-lms-modal-footer">
			<button type="button" class="fs-lms-modal-cancel button">Отмена</button>
			<button type="button" class="fs-lms-modal-confirm button button-primary">Экспортировать</button>
		</div>
	</div>
</div>
