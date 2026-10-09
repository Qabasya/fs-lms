/* ══════════════════════════════════════════════════════════════════════
   Раздел «Статистика» (этап 10): показатели проведения и разбор по заданиям.
   Все числа приходят с сервера — клиент ничего не усредняет и не складывает (кроме долей сегментов полосы для ширины).
   Разметка — существующая: prof-seg (сегменты), prof-stat-tile (плитки), sc-row (строки заданий); ширина сегментов полосы — data-progress
   + applyProgress() (без style). Сеть — createApi(window.fsProfile.exams).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate, plural } from '../utils.js';
import { ajaxErrorText, applyProgress } from '../../common/utils.js';
import { icoCalendarBoard, icoAlert } from '../../common/icons.js';
import { createApi } from '../api.js';
import {
    examConfig, examSubjects, currentSubject, setCurrentSubject, subjectPickerHtml, wireSubjectPicker, noSubjectsHtml,
} from './exam-common.js';

const AUDIENCES = [
    { key: 'all', label: 'Все' },
    { key: 'student', label: 'Ученики' },
    { key: 'guest', label: 'Гости' },
];

const SORTS = [
    { key: 'number', label: 'По номеру' },
    { key: 'hard', label: 'Сначала трудные' },
];

/** Сегменты полосы: порядок и подписи; цвет — класс `exam-seg--{ключ}` (токены вердиктов 7.2.4). */
const SEGMENTS = [
    { key: 'full', cls: 'correct', label: 'полный балл' },
    { key: 'partial', cls: 'partial', label: 'частично' },
    { key: 'incorrect', cls: 'incorrect', label: 'ошибка' },
    { key: 'unanswered', cls: 'unanswered', label: 'пропуск' },
    { key: 'pending', cls: 'pending', label: 'на проверке' },
];

let root = null;
let api = null;
let data = null;
let sort = 'number';
const query = { event_id: 0, session_id: 0, audience: 'all' };

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
            ${subjectPickerHtml(examSubjects(), currentSubject())}
            <div class="exam-results-filters">
                <select data-filter="event_id" aria-label="Проведение">${options(f.events, query.event_id, e => e.title, 'Все проведения')}</select>
                ${f.sessions.length ? `<select data-filter="session_id" aria-label="Сеанс">${options(f.sessions, query.session_id, s => `${fmtDate(s.date)} · ${s.time_start}`, 'Все сеансы')}</select>` : ''}
                <div class="prof-seg" role="group" aria-label="Участники">${AUDIENCES.map(a =>
                    `<button type="button" class="${query.audience === a.key ? 'on' : ''}" data-audience="${a.key}">${esc(a.label)}</button>`).join('')}</div>
            </div>
            ${0 === k.registered ? '<div class="exam-conduct-empty">В выбранном проведении пока нет записей.</div>' : ''}
            <div class="prof-stat-tiles exam-stat-tiles">
                ${tile('Записано', k.registered)}${tile('Начали', k.started)}${tile('Сдали', k.submitted)}${tile('Не явились', k.missed)}${tile('Ожидают проверки', k.pending_review)}
                ${averages.join('')}
            </div>
            ${fmt && fmt.mixed ? '<div class="sc-notice exam-notice">В выборке работы разных форматов: средние посчитаны только по одному из них.</div>' : ''}
            ${tasksHtml()}
        </div>`;
    applyProgress(root);
    wire();
}

function sortedTasks() {
    const list = data.tasks.slice();
    if ('hard' === sort) {
        // Без работ — в конец: сортировать нечего. Остальные — по возрастанию доли полного балла.
        list.sort((a, b) => (0 === a.total) - (0 === b.total) || a.full_share - b.full_share || Number(a.number) - Number(b.number));
    }
    return list;
}

function tasksHtml() {
    if (!data.tasks.length) { return ''; }
    return `<div class="prof-card exam-units">
        <div class="exam-panel-head">
            <div class="exam-panel-title">Разбор по заданиям</div>
            <div class="prof-seg" role="group" aria-label="Порядок">${SORTS.map(s =>
                `<button type="button" class="${sort === s.key ? 'on' : ''}" data-sort="${s.key}">${esc(s.label)}</button>`).join('')}</div>
        </div>
        ${sortedTasks().map(taskRow).join('')}
    </div>`;
}

function taskRow(t) {
    const bar = SEGMENTS.map(s =>
        `<span class="exam-seg exam-seg--${s.cls}" data-progress="${t.total > 0 ? (100 * t[s.key] / t.total) : 0}" title="${esc(s.label)}: ${t[s.key]}"></span>`).join('');
    const avg = null !== t.avg_score && undefined !== t.avg_score ? ` · средний балл ${t.avg_score}` : '';
    return `<div class="sc-row exam-task-row">
        <span class="sc-num">${esc(t.number)}</span>
        <div class="sc-lb">
            <span class="sc-ltitle">Максимум ${esc(String(t.max))} · работ: ${t.total}</span>
            <span class="exam-bar">${bar}</span>
            <span class="sc-lsub">${t.total > 0 ? `${t.full_share}% полный балл${avg}` : 'нет сданных работ'}</span>
        </div>
    </div>`;
}

function wire() {
    wireSubjectPicker('examSubjectBtn', examSubjects(), currentSubject(), key => {
        setCurrentSubject(key);
        Object.assign(query, { event_id: 0, session_id: 0 });
        load();
    });
    root.querySelectorAll('[data-filter]').forEach(sel => sel.addEventListener('change', () => {
        query[sel.dataset.filter] = Number(sel.value);
        if ('event_id' === sel.dataset.filter) { query.session_id = 0; }
        load();
    }));
    root.querySelectorAll('[data-audience]').forEach(btn => btn.addEventListener('click', () => { query.audience = btn.dataset.audience; load(); }));
    root.querySelectorAll('[data-sort]').forEach(btn => btn.addEventListener('click', () => { sort = btn.dataset.sort; render(); }));
}
