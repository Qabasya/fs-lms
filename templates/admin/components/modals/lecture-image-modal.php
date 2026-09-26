<?php
/**
 * Модалка блока «Изображение» шага «Лекция» — те же поля, что у блока
 * «Изображение» статьи (модуль ArticleBlocks): картинка из медиатеки, размер,
 * ширина и подпись. Поведение — src/js/admin/modals/lecture-image-modal.js.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div id="fs-lms-lecture-image-modal" class="fs-lms-modal hidden" role="dialog" aria-modal="true" aria-labelledby="fs-lms-lecture-image-title">
	<div class="fs-lms-modal-backdrop"></div>

	<div class="fs-lms-modal-content fs-modal-md">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title" id="fs-lms-lecture-image-title">Изображение</h2>
			<button type="button" class="fs-lms-modal-close fs-close js-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<div class="fs-form-group">
				<span class="fs-lecture-block__label">Изображение</span>
				<div class="fs-lecture-block__image">
					<img class="fs-lecture-block__preview" alt="" data-lecture-image-preview hidden>
					<div class="fs-lecture-block__image-actions">
						<button type="button" class="button" data-lecture-image-pick>Выбрать изображение</button>
						<button type="button" class="button-link button-link-delete" data-lecture-image-clear hidden>Убрать</button>
					</div>
				</div>
			</div>

			<div class="fs-form-group">
				<label for="fs-lms-lecture-image-size">Размер</label>
				<select id="fs-lms-lecture-image-size" disabled></select>
				<p class="description">Шире колонки лекции картинка не станет.</p>
			</div>

			<div class="fs-form-group">
				<label for="fs-lms-lecture-image-width">Ширина, px</label>
				<input type="number" id="fs-lms-lecture-image-width" min="1" step="1">
				<p class="description">Необязательно. Перекрывает «Размер»; высота подстраивается пропорционально.</p>
			</div>

			<div class="fs-form-group">
				<label for="fs-lms-lecture-image-caption">Подпись</label>
				<input type="text" id="fs-lms-lecture-image-caption">
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="button js-modal-close">Отмена</button>
			<button type="button" class="button button-primary" data-lecture-block-submit disabled>Вставить</button>
		</div>
	</div>
</div>
