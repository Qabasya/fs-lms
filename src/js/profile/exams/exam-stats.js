/* ══════════════════════════════════════════════════════════════════════
   Раздел «Статистика» (этап 10): показатели проведения и таблица «ученики × задания».
   Все числа приходят с сервера — клиент ничего не усредняет и не складывает. Таблица (баллы за задания, первичный и вторичный балл,
   средние первичного и вторичного балла внизу) строится из `data.table`; поиск, группа и школа фильтруют её строки на клиенте по полям строки.
   Разметка — существующая: prof-seg (сегменты), prof-stat-tile (плитки), stu-search (поиск). Сеть — createApi(window.fsProfile.exams).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate, plural } from '../utils.js';
import { ajaxErrorText } from '../../common/utils.js';
import { icoCalendarBoard, icoAlert } from '../../common/icons.js';
import { createApi } from '../api.js';
import { audienceSegHtml, searchHtml, groupSelectHtml, schoolSelectHtml, rowMatches } from './exam-row-filters.js';
import {
    examConfig, examSubjects, currentSubject, setCurrentSubject, subjectPickerHtml, wireSubjectPicker, noSubjectsHtml, newestEventId,
} from './exam-common.js';


/** Режимы страницы: таблица учеников и сводная по заданиям (общий prof-seg). */
const MODES = [
    { key: 'students', label: 'Ученики' },
    { key: 'summary', label: 'Сводная' },
];

/** Порог цвета процента в сводной: от 70 — зелёный, от 40 — жёлтый, ниже — красный (как в таблице преподавателей). */
function shareClass(value) {
    if (null === value || undefined === value) { return ''; }
    if (value >= 70) { return ' exam-cell--correct'; }
    return value >= 40 ? ' exam-cell--partial' : ' exam-cell--incorrect';
}

/** Подписи вердиктов для подсказки ячейки таблицы. */
const CELL_TITLES = { correct: 'верно', partial: 'частично', incorrect: 'неверно', pending: 'на проверке' };

let root = null;
let api = null;
let data = null;
const query = { event_id: 0, session_id: 0, audience: 'all' };
// По умолчанию выбрано последнее добавленное проведение; после выбора «Все проведения» вручную оно не возвращается (до смены предмета).
let eventDefaulted = false;
// Клиентские фильтры таблицы; сбрасываются при смене проведения, сеанса и участников.
const view = { search: '', group: '', school: '', mode: 'students' };

export function renderExamStats(r) {
    root = r;
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
    load();
}

async function load() {
    try {
        data = await api('getStats', { subject_key: currentSubject(), ...query });
        if (!eventDefaulted) {
            eventDefaulted = true;
            const newest = newestEventId(data.filters.events);
            if (newest && !query.event_id) {
                query.event_id = newest;
                data = await api('getStats', { subject_key: currentSubject(), ...query });
            }
        }
    } catch (err) {
        data = null;
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить статистику', ajaxErrorText(err, 'Ошибка загрузки'), true);
        return;
    }
    render();
}

function options(list, current, labelOf, allLabel) {
    return `<option value="0">${esc(allLabel)}</option>` + list.map(x =>
        `<option value="${x.id}"${Number(current) === x.id ? ' selected' : ''}>${esc(labelOf(x))}</option>`).join('');
}

/** Среднее или «—»; подпись под ним — размер выборки («по 5 работам»). */
function avgTile(label, value, sample, suffix = '') {
    const hasValue = null !== value && undefined !== value;
    const note = hasValue ? `по ${sample} ${plural(sample, 'работе', 'работам', 'работам')}` : 'нет проверенных работ';
    return tile(label, hasValue ? `${value}${suffix}` : '—', note);
}

function tile(label, value, note = '') {
    return `<div class="prof-stat-tile exam-stat-tile"><div class="st-top">${esc(label)}</div><div class="st-val">${esc(String(value))}</div><div class="st-delta">${esc(note)}</div></div>`;
}

function render() {
    const f = data.filters;
    const k = data.kpi;
    const fmt = data.format;

    if (!f.events.length) {
        root.innerHTML = emptyState('prof-ktp', icoCalendarBoard(34), 'Нет проведений', 'По этому предмету пока нет опубликованных проведений.');
        return;
    }

    const averages = [avgTile('Средний первичный балл', k.avg_primary, k.sample)];
    if (null !== k.avg_secondary && undefined !== k.avg_secondary) { averages.push(avgTile('Средний вторичный балл', k.avg_secondary, k.sample)); }
    if (null !== k.avg_grade && undefined !== k.avg_grade) { averages.push(avgTile('Средняя отметка', k.avg_grade, k.sample)); }

    root.innerHTML = `
        <div class="exam-stats">
            ${subjectPickerHtml(examSubjects(), currentSubject(), 'examStatsSubjectBtn')}
            <div class="exam-results-filters">
                <select data-filter="event_id" aria-label="Проведение">${options(f.events, query.event_id, e => e.title, 'Все проведения')}</select>
                ${f.sessions.length ? `<select data-filter="session_id" aria-label="Сеанс">${options(f.sessions, query.session_id, s => `${fmtDate(s.date)} · ${s.time_start}`, 'Все сеансы')}</select>` : ''}
                ${audienceSegHtml(query.audience)}
                ${'students' === view.mode ? `${searchHtml(query.audience, view.search, 'examStatsSearch')}${groupSelectHtml(tableRows(), query.audience, view.group)}${schoolSelectHtml(tableRows(), query.audience, view.school)}` : ''}
            </div>
            ${0 === k.registered ? '<div class="exam-conduct-empty">В выбранном проведении пока нет записей.</div>' : ''}
            <div class="prof-stat-tiles exam-stat-tiles">
                ${tile('Записано', k.registered)}${tile('Начали', k.started)}${tile('Сдали', k.submitted)}${tile('Не явились', k.missed)}${tile('Ожидают проверки', k.pending_review)}
                ${averages.join('')}
            </div>
            ${fmt && fmt.mixed ? '<div class="sc-notice exam-notice">В выборке работы разных форматов: средние посчитаны только по одному из них.</div>' : ''}
            <div class="prof-seg exam-stats-modes" role="tablist" aria-label="Вид статистики">${MODES.map(m =>
                `<button type="button" role="tab" class="${view.mode === m.key ? 'on' : ''}" data-mode="${m.key}">${esc(m.label)}</button>`).join('')}</div>
            ${'summary' === view.mode
                ? `<div class="prof-card exam-stats-table" id="examStatsTable">${summaryHtml()}</div>`
                : `<div class="prof-card exam-stats-table" id="examStatsTable">${tableHtml()}</div>`}
        </div>`;
    wire();
}

/** Строки таблицы — из них берутся варианты групп и школ. */
function tableRows() {
    return data.table ? data.table.rows : [];
}

function visibleRows() {
    return tableRows().filter(r => rowMatches(r, { audience: 'all', search: view.search, group: view.group, school: view.school }));
}

/** Балл без лишнего «.0»; пусто — для отсутствующего значения. */
function num(value) {
    return null === value || undefined === value ? '' : String(Number(value));
}

function cellHtml(unit) {
    if (!unit || 'unanswered' === unit.status) { return '<td class="exam-cell"></td>'; }
    const text = 'pending' === unit.status ? '…' : num(unit.score);
    return `<td class="exam-cell exam-cell--${unit.status}" title="${esc(CELL_TITLES[unit.status] || '')}">${text}</td>`;
}

/** Таблица «ученики × задания»; под ней строка средних. Пустой выбор — подсказка вместо пустой рамки. */
function tableHtml() {
    const t = data.table;
    if (!t || !t.rows.length) {
        return '<div class="exam-conduct-empty">В выбранных проведениях пока нет сданных работ.</div>';
    }
    const rows = visibleRows();
    if (!rows.length) { return '<div class="exam-conduct-empty">Никого не нашли: измените поиск или фильтры.</div>'; }

    const head = `<tr><th class="exam-grid-name">ФИО</th>${t.columns.map(c => `<th>${esc(c)}</th>`).join('')}<th class="exam-grid-sum">Первичный балл</th><th class="exam-grid-sum">${esc(t.secondary_label)}</th></tr>`;
    const body = rows.map(r => `<tr>
            <td class="exam-grid-name">${esc(r.name)}</td>
            ${t.columns.map(c => cellHtml(r.units[c])).join('')}
            <td class="exam-grid-sum">${r.primary}</td>
            <td class="exam-grid-sum">${r.pending ? '…' : num(r.secondary)}</td>
        </tr>`).join('');
    const f = t.footer;
    const foot = `<tr class="exam-grid-foot"><td class="exam-grid-name">Средний</td>
            ${t.columns.map(() => '<td></td>').join('')}
            <td class="exam-grid-sum">${num(f.primary)}</td><td class="exam-grid-sum">${num(f.secondary)}</td></tr>`;

    return `<div class="exam-grid-scroll"><table class="exam-grid"><thead>${head}</thead><tbody>${body}</tbody><tfoot>${foot}</tfoot></table></div>`;
}

/** Сводная: сданные работы отдельно сверху, затем таблица «номера заданий × попытки / верные / проценты». */
function summaryHtml() {
    const sm = data.summary;
    if (!sm || !sm.columns.length || !sm.works) {
        return '<div class="exam-conduct-empty">В выбранных проведениях пока нет сданных работ.</div>';
    }
    const cell = (value, suffix = '', cls = '') => `<td class="exam-cell${cls}">${null === value || undefined === value ? '—' : `${value}${suffix}`}</td>`;
    const row = (label, build) => `<tr><th class="exam-grid-name" scope="row">${label}</th>${sm.columns.map(build).join('')}</tr>`;

    return `<div class="exam-summary-works"><span>Сдано работ</span><strong>${sm.works}</strong></div>
        <div class="exam-grid-scroll"><table class="exam-grid exam-grid--summary">
            <thead><tr><th class="exam-grid-name">Задание</th>${sm.columns.map(c => `<th>${esc(c.number)}</th>`).join('')}</tr></thead>
            <tbody>
                ${row('Попытки', c => cell(c.attempts))}
                ${row('Верные решения', c => cell(c.correct))}
                ${row('Процент попыток, %', c => cell(c.attempts_share, '', shareClass(c.attempts_share)))}
                ${row('Процент верных, %', c => cell(c.correct_share, '', shareClass(c.correct_share)))}
            </tbody>
        </table></div>`;
}

/** Перерисовывает только таблицу: поле поиска не теряет фокус на каждой букве. */
function redrawTable() {
    const box = document.getElementById('examStatsTable');
    if (box) { box.innerHTML = tableHtml(); }
}

function wire() {
    wireSubjectPicker('examStatsSubjectBtn', examSubjects(), currentSubject(), key => {
        setCurrentSubject(key);
        Object.assign(query, { event_id: 0, session_id: 0 });
        Object.assign(view, { group: '', school: '' });
        eventDefaulted = false;
        load();
    });
    root.querySelectorAll('[data-filter]').forEach(sel => sel.addEventListener('change', () => {
        query[sel.dataset.filter] = Number(sel.value);
        if ('event_id' === sel.dataset.filter) { query.session_id = 0; }
        Object.assign(view, { group: '', school: '' });
        load();
    }));
    root.querySelectorAll('[data-audience]').forEach(btn => btn.addEventListener('click', () => {
        query.audience = btn.dataset.audience;
        Object.assign(view, { group: '', school: '' });
        load();
    }));
    root.querySelectorAll('[data-mode]').forEach(btn => btn.addEventListener('click', () => {
        if (btn.dataset.mode !== view.mode) { view.mode = btn.dataset.mode; render(); }
    }));
    root.querySelector('#examStatsSearch')?.addEventListener('input', e => { view.search = e.target.value; redrawTable(); });
    root.querySelectorAll('[data-view]').forEach(sel => sel.addEventListener('change', () => { view[sel.dataset.view] = sel.value; redrawTable(); }));
}
