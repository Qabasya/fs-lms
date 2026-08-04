import { icoFile, icoDownload, icoArrowRight, icoChevronDown } from '../../common/icons.js';
import { escapeHtml as esc } from '../../common/utils.js';

/**
 * Строит HTML-строку карточки задания для вставки в DOM.
 *
 * Разметка зеркалит SSR-шаблон all-tasks.php — тот же DOM, чтобы работали
 * общий CSS, bindAnswerToggle (кнопка «Ответ») и делегирование .js-tag-filter.
 * Не обращается к DOM — только строит строку.
 *
 * @param {Object} task - Данные задания из AJAX-ответа (TaskListItemDTO::toArray()).
 * @returns {string} HTML-разметка карточки.
 */
export function buildTaskCard(task) {
    // Кнопка и панель ответа — общий блок со страницей одного задания:
    // панель находится по aria-controls, поэтому id обязан быть уникальным.
    const answerId  = `fs-answer-${esc(task.id)}`;
    const answerBtn = task.answer
        ? `<button type="button" class="fs-answer-toggle js-answer-toggle" aria-expanded="false" aria-controls="${answerId}">Показать ответ</button>`
        : '';

    return `
    <article class="task-card-row" data-task-id="${esc(task.id)}">
        <h2 class="tcr-title"><a class="tcr-title-link" href="${esc(task.url)}">${esc(task.title)}</a></h2>
        <header class="tcr-header">
            <div class="tcr-header-inner">
                <div class="tcr-meta">${_buildTags(task)}</div>
            </div>
        </header>
        ${_buildCondition(task)}
        ${_buildFiles(task.files || [])}
        <footer class="tcr-foot">
            ${answerBtn}
            <div class="tcr-actions">
                <a class="tcr-btn tcr-btn-primary" href="${esc(task.url)}">
                    <span>Смотреть решение</span>
                    ${icoArrowRight(14)}
                </a>
            </div>
        </footer>
        ${_buildAnswerPanel(task.answer, answerId)}
    </article>`;
}

// Ступень палитры чипа приходит из PHP (TagPaletteService): цвет закреплён
// за таксономией, значения живут в SCSS — здесь только класс.
function _colorClass(color) {
    return color > 0 ? ` tcr-tag--c${parseInt(color, 10)}` : '';
}

function _buildTags(task) {
    const chips = [];

    if (task.task_number > 0 && task.task_number_slug) {
        chips.push(
            `<button type="button" class="tcr-tag js-tag-filter${_colorClass(task.task_number_color)}" data-filter="${esc(task.task_number_taxonomy)}" data-value="${esc(task.task_number_slug)}">Задание №${esc(task.task_number)}</button>`
        );
    }

    (task.tags || []).forEach(tag => {
        chips.push(
            `<button type="button" class="tcr-tag js-tag-filter${_colorClass(tag.color)}" data-filter="${esc(tag.taxonomy)}" data-value="${esc(tag.slug)}">${esc(tag.label)}</button>`
        );
    });

    return chips.join('');
}

// Кнопку раскрытия печатаем скрытой: показывает её task-condition.js и только
// тем условиям, которые действительно не влезли в отведённые строки.
function _buildCondition(task) {
    if (!task.condition) return '';

    const id = `tcr-cond-${esc(task.id)}`;

    return `
    <div class="tcr-body js-condition">
        <div class="tcr-condition" id="${id}">${task.condition}</div>
        <button type="button" class="tcr-condition-toggle js-condition-toggle"
            aria-expanded="false" aria-controls="${id}" hidden>
            <span class="js-condition-toggle-label">Показать полностью</span>
            <span class="tcr-condition-toggle-ico" aria-hidden="true">${icoChevronDown(14)}</span>
        </button>
    </div>`;
}

function _buildFiles(files) {
    if (!files.length) return '';

    const items = files.map(f => `
        <a class="tcr-file" href="${esc(f.url)}">
            <span class="tcr-file-icon" aria-hidden="true">${icoFile(17)}</span>
            <span class="tcr-file-name">${esc(f.name)}</span>
            ${f.size ? `<span class="tcr-file-size">${esc(f.size)}</span>` : ''}
            <span class="tcr-file-dl" aria-hidden="true">${icoDownload(17)}</span>
        </a>`).join('');

    return `<div class="tcr-files">${items}</div>`;
}

function _buildAnswerPanel(answer, id) {
    if (!answer) return '';

    return `
    <div id="${id}" class="fs-answer js-answer-panel" hidden>
        <div class="fs-answer-label">Правильный ответ:</div>
        <div class="fs-answer-value">${esc(answer)}</div>
    </div>`;
}
