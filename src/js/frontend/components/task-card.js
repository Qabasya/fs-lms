/**
 * Строит HTML-строку карточки задания для вставки в DOM.
 * Не содержит обращений к DOM — только чистое построение разметки.
 *
 * @param {Object} task - Данные задания из AJAX-ответа (TaskListItemDTO::toArray()).
 * @returns {string} HTML-разметка карточки.
 */
export function buildTaskCard(task) {
    const diffCls   = task.difficulty === 'easy' ? 'd-easy' : task.difficulty === 'hard' ? 'd-hard' : 'd-med';
    const diffLabel = task.difficulty === 'easy' ? 'Базовый' : task.difficulty === 'hard' ? 'Высокий' : 'Повышенный';

    const tags = _buildTags(task, diffCls, diffLabel);
    const filesHtml   = _buildFiles(task.files || []);
    const answerHtml  = _buildAnswer(task.answer);
    const answerTab   = task.answer
        ? `<button role="tab" aria-selected="false" class="tcr-tab js-tab-btn" data-panel="answer">Ответ</button>`
        : '';

    return `
    <article class="task-card-row" data-task-id="${task.id}">
        <header class="tcr-header">
            <div class="tcr-header-inner">
                <div class="tcr-id">№ ${task.id}</div>
                <div class="tcr-meta">${tags}</div>
            </div>
        </header>
        <h2 class="tcr-title">${esc(task.title)}</h2>
        ${task.condition ? `<div class="tcr-body"><p class="tcr-condition">${task.condition}</p></div>` : ''}
        ${filesHtml}
        ${answerHtml}
        <footer class="tcr-foot">
            <div class="tcr-tabs" role="tablist">
                <button role="tab" aria-selected="true" class="tcr-tab is-active js-tab-btn" data-panel="cond">Условие</button>
                ${answerTab}
            </div>
            <div class="tcr-actions">
                <a class="tcr-btn tcr-btn-primary" href="${esc(task.url)}">
                    <span>Смотреть решение</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </a>
            </div>
        </footer>
    </article>`;
}

function _buildTags(task, diffCls, diffLabel) {
    const tags = [];

    if (task.task_number) {
        const inner = task.task_type_url
            ? `<a href="${esc(task.task_type_url)}">Задание №${esc(String(task.task_number))}</a>`
            : `Задание №${esc(String(task.task_number))}`;
        tags.push(`<span class="tcr-tag tcr-tag-mono">${inner}</span>`);
    }
    if (task.source)     tags.push(`<span class="tcr-tag">${esc(task.source)}</span>`);
    if (task.difficulty) tags.push(`<span class="tcr-tag"><span class="tcr-tag-dot ${diffCls}"></span>${esc(diffLabel)}</span>`);
    if (task.year)       tags.push(`<span class="tcr-tag tcr-tag-mono">${esc(task.year)}</span>`);

    return tags.join('');
}

function _buildFiles(files) {
    if (!files.length) return '';
    const items = files.map(f => `
        <a class="tcr-file" href="${esc(f.url)}">
            <span class="tcr-file-icon">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/>
                </svg>
            </span>
            <span class="tcr-file-name">${esc(f.name)}</span>
        </a>`).join('');
    return `<div class="tcr-files">${items}</div>`;
}

function _buildAnswer(answer) {
    if (!answer) return '';
    return `
    <div class="tcr-answer js-answer-panel" hidden>
        <div class="tcr-answer-label">Правильный ответ</div>
        <div class="tcr-answer-value">${esc(answer)}</div>
    </div>`;
}

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}