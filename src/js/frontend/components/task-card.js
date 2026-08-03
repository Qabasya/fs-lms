import { icoFile, icoArrowRight } from '../../common/icons.js';
import { escapeHtml as esc } from '../../common/utils.js';

/**
 * Строит HTML-строку карточки задания для вставки в DOM.
 *
 * Разметка зеркалит SSR-шаблон all-tasks.php — тот же DOM, чтобы работали
 * общий CSS, bindCardTabs (кнопка «Ответ») и делегирование .js-tag-filter.
 * Не обращается к DOM — только строит строку.
 *
 * @param {Object} task - Данные задания из AJAX-ответа (TaskListItemDTO::toArray()).
 * @returns {string} HTML-разметка карточки.
 */
export function buildTaskCard(task) {
    const answerBtn = task.answer
        ? '<button type="button" class="tcr-answer-toggle js-answer-toggle" aria-expanded="false">Ответ</button>'
        : '';

    return `
    <article class="task-card-row" data-task-id="${esc(task.id)}">
        <header class="tcr-header">
            <div class="tcr-header-inner">
                <div class="tcr-meta">${_buildTags(task)}</div>
            </div>
        </header>
        <h2 class="tcr-title"><a class="tcr-title-link" href="${esc(task.url)}">${esc(task.title)}</a></h2>
        ${task.condition ? `<div class="tcr-body"><div class="tcr-condition">${task.condition}</div></div>` : ''}
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
        ${_buildAnswerPanel(task.answer)}
    </article>`;
}

function _buildTags(task) {
    const chips = [];

    if (task.task_number > 0 && task.task_number_slug) {
        chips.push(
            `<button type="button" class="tcr-tag js-tag-filter" data-filter="${esc(task.task_number_taxonomy)}" data-value="${esc(task.task_number_slug)}">Задание №${esc(task.task_number)}</button>`
        );
    }

    (task.tags || []).forEach(tag => {
        chips.push(
            `<button type="button" class="tcr-tag js-tag-filter" data-filter="${esc(tag.taxonomy)}" data-value="${esc(tag.slug)}">${esc(tag.label)}</button>`
        );
    });

    return chips.join('');
}

function _buildFiles(files) {
    if (!files.length) return '';

    const items = files.map(f => `
        <a class="tcr-file" href="${esc(f.url)}">
            <span class="tcr-file-icon" aria-hidden="true">${icoFile(13)}</span>
            <span class="tcr-file-name">${esc(f.name)}</span>
            ${f.size ? `<span class="tcr-file-size">${esc(f.size)}</span>` : ''}
        </a>`).join('');

    return `<div class="tcr-files">${items}</div>`;
}

function _buildAnswerPanel(answer) {
    if (!answer) return '';

    return `
    <div class="tcr-answer js-answer-panel" hidden>
        <div class="tcr-answer-label">Правильный ответ</div>
        <div class="tcr-answer-value">${esc(answer)}</div>
    </div>`;
}
