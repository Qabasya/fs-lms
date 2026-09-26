<?php
/**
 * Модалка блока «Код» шага «Лекция» — те же поля, что у блока «Код» статьи
 * (модуль ArticleBlocks): код как есть + язык плашки. Поведение —
 * src/js/admin/modals/lecture-code-modal.js, вставка в редактор —
 * src/js/admin/services/step-editors/lecture-blocks.js.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div id="fs-lms-lecture-code-modal" class="fs-lms-modal hidden" role="dialog" aria-modal="true" aria-labelledby="fs-lms-lecture-code-title">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-lg">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title" id="fs-lms-lecture-code-title">Код</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-form-group">
				<label for="fs-lms-lecture-code">Код</label>
				<textarea id="fs-lms-lecture-code" class="fs-lecture-block__code" rows="14" spellcheck="false"></textarea>
				<p class="description">Вставьте код как есть — экранировать символы не нужно. Tab добавляет отступ в 4 пробела.</p>
			</div>

			<div class="fs-form-group">
				<label for="fs-lms-lecture-code-lang">Язык</label>
				<select id="fs-lms-lecture-code-lang"></select>
				<p class="description">Подпись на плашке. Подсветка синтаксиса — для Python.</p>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button js-modal-close">Отмена</button>
			<button type="button" class="button button-primary" data-lecture-block-submit>Вставить</button>
		</div>
	</div>
</div>
