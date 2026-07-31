<?php
/**
 * Модалка экспорта предмета: выбор формата и объёма (Этап 6).
 *
 * Единственная точка выгрузки — форматов два, и разница между ними не очевидна
 * из названий кнопок, поэтому выбор сведён в одно окно: JSON — быстрый снимок
 * структуры и банка, ZIP — полный перенос с медиа и (по требованию) учениками.
 * Здесь же проговаривается то, о чём администратор иначе узнает уже на целевом
 * сайте: прогресс обучения не переносится, а пакет с учениками содержит
 * персональные данные и пароли учётных записей в открытом виде.
 *
 * @package Inc\Templates
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div id="fs-lms-bundle-export-modal" class="fs-lms-modal hidden">
	<div class="fs-lms-modal-backdrop"></div>
	<div class="fs-lms-modal-content fs-modal-md">
		<div class="fs-lms-modal-header">
			<h2 class="fs-lms-modal-title">Экспорт предмета</h2>
			<button type="button" class="fs-lms-modal-close" aria-label="Закрыть">&times;</button>
		</div>

		<div class="fs-lms-modal-body">
			<p class="fs-lms-modal-message js-bundle-export-summary"></p>

			<label class="fs-bundle__option">
				<input type="radio" name="fs-bundle-format" value="json" class="js-bundle-format" checked>
				<span>Только структура предмета — файл JSON</span>
			</label>
			<p class="fs-bundle__hint">
				Таксономии, метабоксы, заготовки и банк заданий со статьями.
				Без медиафайлов, учебной программы и учеников — легко и быстро.
			</p>

			<label class="fs-bundle__option">
				<input type="radio" name="fs-bundle-format" value="zip" class="js-bundle-format">
				<span>Предмет целиком — архив ZIP</span>
			</label>
			<p class="fs-bundle__hint">
				Всё содержимое предмета одним файлом. Состав архива — ниже.
			</p>

			<div class="fs-bundle__scope js-bundle-scope">
				<label class="fs-bundle__option">
					<input type="checkbox" class="js-bundle-curriculum" checked>
					<span>Учебная программа — работы, контрольные, уроки, курсы</span>
				</label>
				<p class="fs-bundle__hint">
					Без этой галочки в пакет войдут только банк заданий и статей.
					Задачи глобального банка подтягиваются автоматически — только те,
					на которые ссылаются включённые работы и контрольные.
				</p>

				<label class="fs-bundle__option">
					<input type="checkbox" class="js-bundle-media" checked>
					<span>Медиафайлы — вложения заданий, аудио, материалы</span>
				</label>
				<p class="fs-bundle__hint">
					Файлы кладутся внутрь архива и заливаются в медиабиблиотеку целевого
					сайта. Без них ссылки на вложения восстановлены не будут.
				</p>

				<label class="fs-bundle__option">
					<input type="checkbox" class="js-bundle-students">
					<span>Ученики этого предмета — учётки, группы, зачисление</span>
				</label>

				<div class="notice notice-warning inline fs-bundle__warning js-bundle-students-warning hidden">
					<p>
						Пакет с учениками содержит персональные данные <strong>и пароли учётных
						записей открытым текстом</strong>: контакты, документы и пароли
						расшифровываются, иначе целевой сайт их не прочитает. Пароли переносятся
						намеренно — чтобы ученики и родители вошли на новом сайте по прежним данным.
						Храните и передавайте архив только защищённым каналом и удаляйте после импорта.
					</p>
				</div>

				<div class="notice notice-info inline fs-bundle__notice">
					<p>
						Прогресс обучения не переносится: посещаемость, сдачи работ, попытки
						и оценки останутся на этом сайте. На целевом сайте ученики будут
						зачислены и увидят курс, но начнут прохождение с нуля.
					</p>
				</div>
			</div>
		</div>

		<div class="fs-lms-modal-footer">
			<button type="button" class="fs-lms-modal-cancel button">Отмена</button>
			<button type="button" class="fs-lms-modal-confirm button button-primary">Выгрузить</button>
		</div>
	</div>
</div>
