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
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { copyToClipboard } from '../../common/utils.js';

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
// Школьные отчёты (этап 12.2.8): режим выбора строк («Создать отчёт») и панель отчётов выбранного проведения.
let reports = null; // { reports, blocked, sources } — только когда выбрано проведение и есть право ShareExamResults
let reporting = false;
let reportPicked = new Set();
let reportForm = false;
let editingReport = 0;
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
    resetReporting();
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
    await loadReports();
    render();
}

function canShare() {
    return Boolean(examConfig()?.canShareResults);
}

function resetReporting() {
    reporting = false;
    reportPicked = new Set();
    reportForm = false;
    editingReport = 0;
}

/** Отчёты нужны только при выбранном проведении: состав и причины отказа считаются по одному проведению. */
async function loadReports() {
    reports = null;
    if (!canShare() || !(query.event_id > 0)) { return; }
    try {
        reports = await api('getReports', { event_id: query.event_id });
    } catch (err) {
        toast(ajaxErrorText(err, 'Не удалось загрузить отчёты'), 'error');
    }
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
            ${reportsPanel()}
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
    const report = reportToolbar();
    return approval || exports || report ? `<div class="exam-conduct-toolbar">${report}${approval}${exports}</div>` : '';
}

/** «Создать отчёт»: только при праве и выбранном проведении; без проведения — неактивная кнопка с подсказкой. */
function reportToolbar() {
    if (!canShare()) { return ''; }
    if (!reports) {
        return '<button type="button" class="prof-btn prof-btn-sm" disabled title="Выберите проведение, чтобы собрать отчёт">Создать отчёт</button>';
    }
    if (!reporting) { return '<button type="button" class="prof-btn prof-btn-sm" data-report="start">Создать отчёт</button>'; }
    return `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-report="next"${editingReport ? ' hidden' : ''}>Создать отчёт (${reportPicked.size})</button>
        <button type="button" class="prof-btn prof-btn-sm" data-report="cancel">${editingReport ? 'Готово' : 'Отмена'}</button>`;
}

const REPORT_STATE = { active: 'действует', revoked: 'отозван', expired: 'истёк' };

/** Форма нового отчёта и список отчётов проведения: ссылка, отзыв, изменение состава. */
function reportsPanel() {
    if (!reports) { return ''; }
    const form = reportForm ? `<div class="gp-form gp-exam exam-report-form">
            <div class="gp-title">Новый отчёт</div>
            <label class="gp-field"><span>Название</span><input type="text" id="repTitle" maxlength="255" placeholder="Например, Школа № 5, 11 класс"></label>
            <label class="gp-field"><span>Получатель</span><select id="repSource"><option value="0">Без получателя</option>${reports.sources.map(s => `<option value="${s.id}">${esc(s.label)}</option>`).join('')}</select></label>
            <label class="gp-field"><span>Срок, дней</span><input type="number" id="repDays" min="1" max="90" value="90"></label>
            <div class="gp-error" id="repError" role="alert" hidden></div>
            <div class="gp-row">
                <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-report="save">Создать отчёт (${reportPicked.size})</button>
                <button type="button" class="prof-btn prof-btn-sm" data-report="close-form">Назад к выбору</button>
            </div>
        </div>` : '';
    if (!reports.reports.length && !form) { return ''; }
    const rows = reports.reports.map(r => `<div class="pr-row exam-report-row" data-report-id="${r.id}" data-version="${r.version}">
            <div class="pr-info">
                <div class="pr-name">${esc(r.title)}</div>
                <div class="pr-sub">${r.participation_ids.length} уч. · ${REPORT_STATE[r.state] || ''}${'active' === r.state ? ` до ${esc(fmtDate(r.expires_at.slice(0, 10)))}` : ''}</div>
            </div>
            ${'active' === r.state ? `<button type="button" class="prof-btn prof-btn-sm" data-report-act="copy">${r.link_issued ? 'Скопировать ссылку (новая)' : 'Скопировать ссылку'}</button>
            <button type="button" class="prof-btn prof-btn-sm" data-report-act="edit">Изменить состав</button>
            <button type="button" class="prof-btn prof-btn-sm" data-report-act="revoke">Отозвать</button>` : ''}
        </div>`).join('');
    return `<div class="exam-reports">${form}${rows ? `<div class="pr-list">${rows}</div>` : ''}</div>`;
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
    const blocked = reporting ? (reports.blocked[r.participation_id] || '') : '';
    const inReport = editingReport && reportById(editingReport)?.participation_ids.includes(r.participation_id);
    const check = reporting
        ? `<input type="checkbox" class="exam-report-check" data-participation="${r.participation_id}" aria-label="Включить в отчёт"${blocked && !inReport ? ` disabled title="${esc(blocked)}"` : (reportPicked.has(r.participation_id) ? ' checked' : '')}>`
        : (selecting
            ? `<input type="checkbox" class="exam-conduct-check" data-attempt="${r.attempt_id}" aria-label="Выбрать работу"${reason ? ` disabled title="${esc(reason)}"` : (selected.has(r.attempt_id) ? ' checked' : '')}>`
            : '');
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
    wireReports();

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

function reportById(id) {
    return reports?.reports.find(r => r.id === id);
}

/** Действия отчётов: режим выбора, форма, создание, ссылка, отзыв, изменение состава. */
function wireReports() {
    root.querySelector('[data-report="start"]')?.addEventListener('click', () => { reporting = true; reportPicked = new Set(); editingReport = 0; render(); });
    root.querySelector('[data-report="cancel"]')?.addEventListener('click', () => { resetReporting(); load(); });
    root.querySelector('[data-report="next"]')?.addEventListener('click', () => {
        if (!reportPicked.size) { toast('Выберите хотя бы одного участника', 'error'); return; }
        reportForm = true;
        render();
    });
    root.querySelector('[data-report="close-form"]')?.addEventListener('click', () => { reportForm = false; render(); });
    root.querySelector('[data-report="save"]')?.addEventListener('click', saveReport);

    root.querySelectorAll('.exam-report-check').forEach(box => box.addEventListener('change', () => changeReportSelection(box)));

    root.querySelectorAll('.exam-report-row').forEach(row => {
        const id = Number(row.dataset.reportId);
        row.querySelector('[data-report-act="copy"]')?.addEventListener('click', async () => {
            const report = reportById(id);
            if (report?.link_issued && !await confirmDialog('Прежняя ссылка перестанет работать.', 'Перевыпустить', 'Не перевыпускать')) { return; }
            try {
                const { url } = await api('issueReportLink', { report_id: id });
                toast(await copyToClipboard(url) ? 'Ссылка скопирована. Передайте её школе.' : 'Не удалось скопировать ссылку', 'ok');
                await load();
            } catch (err) { toast(ajaxErrorText(err, 'Не удалось выдать ссылку'), 'error'); }
        });
        row.querySelector('[data-report-act="revoke"]')?.addEventListener('click', async () => {
            if (!await confirmDialog('Ссылка на отчёт перестанет работать, школа потеряет доступ.', 'Отозвать', 'Не отзывать')) { return; }
            try {
                await api('revokeReportLink', { report_id: id, version: reportById(id).version });
                toast('Отчёт отозван');
                await load();
            } catch (err) { toast(ajaxErrorText(err, 'Не удалось отозвать отчёт'), 'error'); await load(); }
        });
        row.querySelector('[data-report-act="edit"]')?.addEventListener('click', () => {
            reporting = true;
            editingReport = id;
            reportPicked = new Set(reportById(id).participation_ids);
            render();
        });
    });
}

/** В режиме «Изменить состав» каждое переключение сразу сохраняется (с версией отчёта); при создании — только копится выбор. */
async function changeReportSelection(box) {
    const participationId = Number(box.dataset.participation);
    if (!editingReport) {
        if (box.checked) { reportPicked.add(participationId); } else { reportPicked.delete(participationId); }
        const next = root.querySelector('[data-report="next"]');
        if (next) { next.textContent = `Создать отчёт (${reportPicked.size})`; }
        return;
    }
    try {
        reports = await api(box.checked ? 'addReportMember' : 'removeReportMember', {
            report_id: editingReport, participation_id: participationId, version: reportById(editingReport).version,
        });
        if (box.checked) { reportPicked.add(participationId); } else { reportPicked.delete(participationId); }
    } catch (err) {
        box.checked = !box.checked;
        toast(ajaxErrorText(err, 'Не удалось изменить состав'), 'error');
        await loadReports();
        render();
    }
}

async function saveReport() {
    const error = root.querySelector('#repError');
    try {
        const source = Number(root.querySelector('#repSource').value);
        reports = await api('saveReport', {
            event_id: query.event_id,
            title: root.querySelector('#repTitle').value,
            participation_ids: [...reportPicked],
            recipient_source_id: source || '',
            days: root.querySelector('#repDays').value,
        });
        toast('Отчёт создан');
        resetReporting();
        render();
    } catch (err) {
        error.hidden = false;
        error.textContent = ajaxErrorText(err, 'Не удалось создать отчёт');
    }
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
