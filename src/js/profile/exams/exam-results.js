/* ══════════════════════════════════════════════════════════════════════
   Раздел «Результаты» (этап 8.7): работы проведений предмета с фильтрами и очередью проверки.
   Разметка — существующая: prof-seg (статус), pr-row (работы), prof-state-pill, prof-stat-tile не нужен.
   По умолчанию открывается очередь «На проверке». Клик по строке открывает экран проверки (`exam-results` — экран возврата).
   В сегменте «Готовы к утверждению» работает то же массовое утверждение, что и на доске сеанса (общие функции — exam-common.js).
   Сеть — createApi(window.fsProfile.exams). Права и список проведений решает сервер.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState, fmtDate } from '../utils.js';
import { ajaxErrorText } from '../../common/utils.js';
import { icoAlert } from '../../common/icons.js';
import { createApi } from '../api.js';
import {
    examConfig, examSubjects, currentSubject, setCurrentSubject, subjectPickerHtml, wireSubjectPicker, noSubjectsHtml,
    approvalBlockReason, approvableRows, approveButtonLabel, approvalItems, approvalSummaryText, skippedListHtml, exportButtonsHtml, wireExportButtons,
} from './exam-common.js';
import { scoreText } from './exam-conduct.js';

const SEGMENTS = [
    { key: 'pending_review', label: 'На проверке' },
    { key: 'ready', label: 'Готовы к утверждению' },
    { key: 'approved', label: 'Утверждены' },
    { key: 'all', label: 'Все' },
];

const AUDIENCES = [
    { key: 'all', label: 'Все' },
    { key: 'student', label: 'Ученики' },
    { key: 'guest', label: 'Гости' },
];

const EMPTY_TEXT = {
    pending_review: 'Работ, ожидающих проверки, нет.',
    ready: 'Работ, готовых к утверждению, нет.',
    approved: 'Утверждённых работ пока нет.',
    all: 'Работ пока нет.',
};

let root = null;
let api = null;
let handlers = {};
let data = null;
let selecting = false;
let selected = new Set();
let lastSkipped = [];
const query = { event_id: 0, session_id: 0, status: 'pending_review', audience: 'all', source_id: 0 };

export function renderExamResults(r, h = {}) {
    root = r;
    handlers = h;
    const cfg = examConfig();
    if (!cfg) {
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Данные экзаменов недоступны', '', true);
        return;
    }
    if (!examSubjects().length) {
        root.innerHTML = noSubjectsHtml();
        return;
    }
    api = createApi(cfg);
    selecting = false;
    selected = new Set();
    load();
}

async function load() {
    try {
        data = await api('getResults', { subject_key: currentSubject(), ...query });
    } catch (err) {
        data = null;
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить результаты', ajaxErrorText(err, 'Ошибка загрузки'), true);
        return;
    }
    render();
}

function optionsHtml(list, current, valueKey, labelKey, allLabel) {
    return `<option value="0">${esc(allLabel)}</option>` + list.map(x =>
        `<option value="${x[valueKey]}"${Number(current) === Number(x[valueKey]) ? ' selected' : ''}>${esc(x[labelKey])}</option>`).join('');
}

function render() {
    const f = data.filters;
    const sessions = f.sessions.map(x => ({ id: x.id, label: `${fmtDate(x.date)} · ${x.time_start}` }));
    root.innerHTML = `
        <div class="exam-results">
            ${subjectPickerHtml(examSubjects(), currentSubject())}
            <div class="prof-seg exam-results-segs" role="tablist">${SEGMENTS.map(s =>
                `<button type="button" role="tab" class="${query.status === s.key ? 'on' : ''}" data-status="${s.key}">${esc(s.label)}</button>`).join('')}</div>
            <div class="exam-results-filters">
                <select data-filter="event_id" aria-label="Проведение">${optionsHtml(f.events, query.event_id, 'id', 'title', 'Все проведения')}</select>
                ${sessions.length ? `<select data-filter="session_id" aria-label="Сеанс">${optionsHtml(sessions, query.session_id, 'id', 'label', 'Все сеансы')}</select>` : ''}
                <div class="prof-seg" role="group" aria-label="Участники">${AUDIENCES.map(a =>
                    `<button type="button" class="${query.audience === a.key ? 'on' : ''}" data-audience="${a.key}">${esc(a.label)}</button>`).join('')}</div>
                ${f.sources.length ? `<select data-filter="source_id" aria-label="Источник">${optionsHtml(f.sources, query.source_id, 'id', 'label', 'Все источники')}</select>` : ''}
            </div>
            ${toolbar()}
            ${skippedListHtml(lastSkipped, id => data.items.find(r => r.attempt_id === id)?.name)}
            ${data.items.length
                ? `<div class="pr-list">${data.items.map(rowHtml).join('')}</div>`
                : `<div class="exam-conduct-empty">${esc(EMPTY_TEXT[query.status])}</div>`}
        </div>`;
    wire();
}

/** Утверждение (в сегменте «Готовы») и выгрузка выбранной выборки (CSV, печать — при обоих экспортных правах). */
function toolbar() {
    const approval = approvalToolbar();
    const exports = data.items.length ? exportButtonsHtml() : '';
    return approval || exports ? `<div class="exam-conduct-toolbar">${approval}${exports}</div>` : '';
}

function approvalToolbar() {
    if ('ready' !== query.status || (!approvableRows(data.items).length && !selecting)) { return ''; }
    if (!selecting) {
        return '<button type="button" class="prof-btn prof-btn-sm" data-approve="start">Утвердить работы</button>';
    }
    return `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-approve="confirm">${esc(approveButtonLabel(selected.size))}</button>
        <button type="button" class="prof-btn prof-btn-sm" data-approve="cancel">Отмена</button>`;
}

function rowHtml(r) {
    const reason = approvalBlockReason(r);
    const check = selecting
        ? `<input type="checkbox" class="exam-conduct-check" data-attempt="${r.attempt_id}" aria-label="Выбрать работу"${reason ? ` disabled title="${esc(reason)}"` : (selected.has(r.attempt_id) ? ' checked' : '')}>`
        : '';
    const when = r.session_date ? `${esc(fmtDate(r.session_date))} · ${esc(r.session_time)}` : '';
    return `<div class="pr-row pr-row--link exam-results-row" data-attempt="${r.attempt_id}" tabindex="0">
        ${check}
        <div class="pr-info">
            <div class="pr-name">${esc(r.name)}</div>
            <div class="pr-sub">${esc(r.event_title)}${when ? ' · ' + when : ''} · ${esc(r.audience_label)}${r.source ? ' · ' + esc(r.source) : ''}</div>
        </div>
        ${r.score ? `<span class="exam-conduct-score">${scoreText(r.score)}</span>` : ''}
        <span class="prof-state-pill prof-state-soon">${esc(r.result_status_label)}</span>
    </div>`;
}

function wire() {
    wireSubjectPicker('examSubjectBtn', examSubjects(), currentSubject(), key => {
        setCurrentSubject(key);
        Object.assign(query, { event_id: 0, session_id: 0, source_id: 0 });
        load();
    });
    root.querySelectorAll('[data-status]').forEach(btn => btn.addEventListener('click', () => {
        query.status = btn.dataset.status;
        selecting = false;
        selected = new Set();
        lastSkipped = [];
        load();
    }));
    root.querySelectorAll('[data-audience]').forEach(btn => btn.addEventListener('click', () => { query.audience = btn.dataset.audience; load(); }));
    root.querySelectorAll('[data-filter]').forEach(sel => sel.addEventListener('change', () => {
        query[sel.dataset.filter] = Number(sel.value);
        // Смена проведения сбрасывает фильтры, зависящие от него.
        if ('event_id' === sel.dataset.filter) { query.session_id = 0; query.source_id = 0; }
        load();
    }));

    wireExportButtons(root, api, () => ({ participation_ids: data.items.map(r => r.participation_id) }));
    root.querySelector('[data-approve="start"]')?.addEventListener('click', () => { selecting = true; selected = new Set(); render(); });
    root.querySelector('[data-approve="cancel"]')?.addEventListener('click', () => { selecting = false; selected = new Set(); render(); });
    root.querySelector('[data-approve="confirm"]')?.addEventListener('click', approveSelected);

    root.querySelectorAll('.exam-results-row').forEach(row => {
        const open = () => handlers.openWorkReview?.('attempt', Number(row.dataset.attempt));
        row.addEventListener('click', e => { if (!e.target.closest('input')) { open(); } });
        row.addEventListener('keydown', e => { if ('Enter' === e.key) { open(); } });
    });
    root.querySelectorAll('.exam-conduct-check').forEach(box => box.addEventListener('change', () => {
        const id = Number(box.dataset.attempt);
        if (box.checked) { selected.add(id); } else { selected.delete(id); }
        const confirm = root.querySelector('[data-approve="confirm"]');
        if (confirm) { confirm.textContent = approveButtonLabel(selected.size); }
    }));
}

async function approveSelected() {
    const items = approvalItems(data.items, selected);
    if (!items.length) { toast('Нет работ, готовых к утверждению', 'error'); return; }
    try {
        const result = await api('approveAttempts', { items });
        toast(approvalSummaryText(result));
        lastSkipped = result.skipped || [];
        selecting = false;
        selected = new Set();
        await load();
    } catch (err) {
        toast(ajaxErrorText(err, 'Не удалось утвердить работы'), 'error');
    }
}
