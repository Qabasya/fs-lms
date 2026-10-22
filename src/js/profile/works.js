/* ══════════════════════════════════════════════════════════════════════
   Вкладка «Работы» (D3, .docs/Tasks.md) — список работ/экзаменов на
   проверку, без обхода по ученикам (Сводка → группа → ученик → занятие).
   Три вкладки (На проверке / Проверенные / Прошедшие) + фильтры
   группа/тип работы/сортировка. «Прошедшие» — все работы состоявшихся занятий:
   в работе видны ученики, а несдавшим работу можно «Засчитать» (максимальный балл). Шаг 1 — работы активной вкладки; шаг 2 —
   сдачи выбранной работы/экзамена → переход на work-review (D2).
   Источник: window.fsProfile.{groups, works:{nonce,actions}}.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate, fmtDateTime, toast } from './utils.js';
import { icoInbox } from '../common/icons.js';
import { createApi, clearApiCache } from './api.js';
import { prefetchWorkReview } from './work-review.js';
import { groupPickerBtnHtml, openGroupPicker } from './picker.js';
import { badgeHtml, workCardHtml } from './work-card.js';
import { confirmDialog } from '../common/components/confirm-dialog.js';
import { readNav, saveNav } from './nav-state.js';

const TABS = [
    { key: 'pending', label: 'На проверке' },
    { key: 'done', label: 'Проверенные' },
    { key: 'passed', label: 'Прошедшие' },
];

/** Вкладка «Прошедшие»: элементы списка строятся из PassedWorksService, а не из очереди проверки. */
const PASSED = 'passed';

const WORK_TYPE_OPTIONS = [
    { value: '', label: 'Все типы работ' },
    { value: 'Практика', label: 'Практика' },
    { value: 'Самостоятельная работа', label: 'Самостоятельная работа' },
    { value: 'Домашнее задание', label: 'Домашнее задание' },
    { value: 'Экзамен', label: 'Экзамен' },
];

let root = null;
let api = null;
let openWorkReviewCb = null;

let state = null;
let listRequest = 0;
const countGenerations = { pending: 0, done: 0, passed: 0 };
let countsSweep = 0;
let submissionsRequest = 0;

/** @param {{ openWorkReview?: (sourceType: string, sourceId: number) => void }} [opts] */
export function renderWorks(r, opts = {}) {
    root = r;
    openWorkReviewCb = typeof opts.openWorkReview === 'function' ? opts.openWorkReview : null;
    const p = window.fsProfile || {};
    // Вкладка и группа из адреса (nav-state.js): «Назад» из плеера возвращает на ту же вкладку.
    const nav = readNav('works');
    state = {
        groups:     Array.isArray(p.groups) ? p.groups : [],
        cfg:        p.works || null,
        tab:        TABS.some((t) => t.key === nav.tab) ? nav.tab : 'pending',
        filterGroupId: Number(nav.g) || 0, // 0 = все группы
        filterType: '',
        sort:       'new', // new | old
        items:      null,  // список работ активной вкладки (шаг 1)
        counts:     {},    // tab => суммарное число сдач в корзине
        selected:   null,  // выбранная работа (шаг 2)
        submissions: null,
        refreshing: false,
    };
    // Очередь и сдачи — короткий кеш: переключение между работами не ждёт сервер;
    // «Обновить» и любая запись (зачёт, оценка) сбрасывают его.
    api = createApi(state.cfg, { cache: {
        getPendingWorks:    30000,
        getWorkSubmissions: 30000,
        getPassedWorks:     60000,
        getPassedStudents:  30000,
    } });
    if (!state.cfg) { root.innerHTML = empty('Проверка недоступна', 'Экран «Работы» не настроен.'); return; }

    loadAllCounts();
    loadTab(state.tab);
}

/* ── Data ─────────────────────────────────────────────────────────────── */
/** Строка вкладки «Прошедшие» приводится к виду очереди: общие фильтры, сортировка и подсчёт работают одинаково. */
function passedItem(it) {
    return Object.assign({}, it, {
        source_type: PASSED,
        source_id:   `${it.group_lesson_id}:${it.work_id}`,
        group_ids:   [it.group_id],
        latest_at:   it.date,
        count:       it.missing,
    });
}

/** Работы вкладки: очередь проверки или прошедшие занятия. */
async function fetchItems(tab) {
    if (PASSED === tab) {
        return (await api('getPassedWorks', { group_id: 0 })).map(passedItem);
    }
    return api('getPendingWorks', { tab });
}

/** Сдачи выбранной работы; у «Прошедших» — ученики занятия (сдавшие и нет). */
function fetchSubmissions(item, tab) {
    if (PASSED === item.source_type) {
        return api('getPassedStudents', { group_lesson_id: item.group_lesson_id, work_id: item.work_id });
    }
    return api('getWorkSubmissions', { source_type: item.source_type, source_id: item.source_id, tab });
}

async function loadAllCounts() {
    const sweepId = ++countsSweep;
    const versions = Object.fromEntries(TABS.map((t) => [t.key, ++countGenerations[t.key]]));
    await Promise.all(TABS.map(async (t) => {
        try {
            const items = await fetchItems(t.key);
            if (sweepId !== countsSweep || versions[t.key] !== countGenerations[t.key] || !state) { return; }
            if (t.key !== state.tab) {
                state.counts[t.key] = items.reduce((sum, it) => sum + (it.count || 0), 0);
            }
        } catch { /* тихо — бейдж останется пустым */ }
    }));
    if (sweepId === countsSweep && state) { render(); }
}

async function loadTab(tab) {
    const requestId = ++listRequest;
    ++countGenerations[tab];
    ++submissionsRequest;
    state.tab = tab;
    state.selected = null;
    state.submissions = null;
    state.items = null;
    render();
    try {
        const items = await fetchItems(tab);
        if (requestId !== listRequest) { return; }
        state.items = items;
        state.counts[tab] = state.items.reduce((sum, it) => sum + (it.count || 0), 0);
    } catch (e) {
        if (requestId !== listRequest) { return; }
        state.items = [];
        if (state.refreshing) { toast(e.message || 'Не удалось обновить список работ.', 'error'); }
    }
    render();
}

async function loadSubmissions(item) {
    const requestId = ++submissionsRequest;
    state.selected = item;
    state.submissions = null;
    render();
    try {
        const submissions = await fetchSubmissions(item, state.tab);
        if (requestId !== submissionsRequest) { return; }
        state.submissions = submissions;
    } catch (e) {
        if (requestId !== submissionsRequest) { return; }
        state.submissions = [];
        if (state.refreshing) { toast(e.message || 'Не удалось обновить сдачи.', 'error'); }
    }
    render();
}

/** Refresh the current list/detail without discarding its tab, filters, or selection. */
async function refreshWorks() {
    if (state.refreshing) { return; }
    clearApiCache();
    state.refreshing = true;
    render();
    const tab = state.tab;
    const selected = state.selected;
    const listRequestId = ++listRequest;
    const countsSweepId = ++countsSweep;
    const countVersions = Object.fromEntries(TABS.map((t) => [t.key, ++countGenerations[t.key]]));
    const submissionsRequestId = ++submissionsRequest;
    const refreshes = TABS.map(async (t) => {
        try {
            const items = await fetchItems(t.key);
            if (countsSweepId !== countsSweep || countVersions[t.key] !== countGenerations[t.key]) { return; }
            state.counts[t.key] = items.reduce((sum, it) => sum + (it.count || 0), 0);
            if (t.key === tab && listRequestId === listRequest) { state.items = items; }
        } catch (e) {
            if (t.key === tab && listRequestId === listRequest) {
                toast(e.message || 'Не удалось обновить список работ.', 'error');
            }
        }
    });
    if (selected) {
        refreshes.push((async () => {
            try {
                const items = await fetchSubmissions(selected, tab);
                if (submissionsRequestId === submissionsRequest && state.selected === selected) {
                    state.submissions = items;
                }
            } catch (e) {
                if (submissionsRequestId === submissionsRequest && state.selected === selected) {
                    toast(e.message || 'Не удалось обновить сдачи.', 'error');
                }
            }
        })());
    }
    try {
        await Promise.allSettled(refreshes);
    } finally {
        state.refreshing = false;
        render();
    }
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    saveNav('works', { tab: state.tab, g: state.filterGroupId });
    root.innerHTML = `
    <div class="prof-works">
        ${tabsHtml()}
        <div class="prof-swap">${state.selected ? stepTwoHtml() : stepOneHtml()}</div>
    </div>`;

    wireTabs();
    if (state.selected) { wireStepTwo(); } else { wireStepOne(); }
}

function tabsHtml() {
    return `<div class="wk-tabs">${TABS.map((t) => `
        <button type="button" class="wk-tab${t.key === state.tab ? ' active' : ''}" data-tab="${t.key}">
            ${esc(t.label)}<span class="wk-tab-badge">${state.counts[t.key] ?? '·'}</span>
        </button>`).join('')}</div>`;
}

function stepOneHtml() {
    if (null === state.items) {
        return '<div class="wk-loading">Загрузка…</div>';
    }

    const items = visibleItems();
    const list = items.length
        ? `<div class="wk-list">${items.map(itemRow).join('')}</div>`
        : emptyState('wk-empty-wrap', icoInbox(34), PASSED === state.tab ? 'Работ нет' : 'Работ на проверку нет', tabEmptyText());

    return `${filtersHtml()}${list}`;
}

function tabEmptyText() {
    return 'pending' === state.tab ? 'Все сдачи проверены.'
        : PASSED === state.tab ? 'Работ в прошедших занятиях нет.'
        : 'Проверенных работ пока нет.';
}

function filtersHtml() {
    const g = state.groups.find((x) => x.id === state.filterGroupId);
    return `<div class="wk-filters">
        <div class="wk-filter">
            ${groupPickerBtnHtml(g || { id: 0, name: 'Все группы', subject: '' }, 'wkGroupBtn')}
        </div>
        <select class="wk-select" id="wkTypeFilter">
            ${WORK_TYPE_OPTIONS.map((o) => `<option value="${esc(o.value)}"${o.value === state.filterType ? ' selected' : ''}>${esc(o.label)}</option>`).join('')}
        </select>
        <select class="wk-select" id="wkSort">
            <option value="new"${'new' === state.sort ? ' selected' : ''}>Сначала новые</option>
            <option value="old"${'old' === state.sort ? ' selected' : ''}>Сначала старые</option>
        </select>
        <button type="button" class="prof-btn prof-btn-sm wk-refresh" data-refresh${state.refreshing ? ' disabled' : ''}>${state.refreshing ? 'Обновление…' : 'Обновить'}</button>
    </div>`;
}

function visibleItems() {
    let items = state.items || [];
    if (state.filterGroupId) {
        items = items.filter((it) => (it.group_ids || []).includes(state.filterGroupId));
    }
    if (state.filterType) {
        items = items.filter((it) => it.label === state.filterType);
    }
    items = items.slice().sort((a, b) => {
        const at = a.latest_at || '';
        const bt = b.latest_at || '';
        return 'old' === state.sort ? at.localeCompare(bt) : bt.localeCompare(at);
    });
    return items;
}

/* «Прошедшие»: работа занятия — группа, тема, дата и сколько учеников сдали. */
function passedRow(it) {
    return `<div class="wk-row" data-src-type="${PASSED}" data-src-id="${esc(it.source_id)}">
        <span class="wk-type">${badgeHtml(it.badge)}</span>
        <span class="wk-row-title" title="${esc(it.title)}">${esc(it.title)}<span class="wk-row-sub">${esc([it.group_name, it.topic].filter(Boolean).join(' · '))}</span></span>
        <span class="wk-row-count">сдали ${it.submitted} из ${it.total}</span>
        <span class="wk-row-date">${esc(fmtDate(it.date))}</span>
        <button type="button" class="prof-btn prof-btn-sm">Открыть</button>
    </div>`;
}

function itemRow(it) {
    if (PASSED === it.source_type) { return passedRow(it); }
    return `<div class="wk-row" data-src-type="${esc(it.source_type)}" data-src-id="${it.source_id}">
        <span class="wk-type">${badgeHtml(it.badge)}</span>
        <span class="wk-row-title" title="${esc(it.title)}">${esc(it.title)}</span>
        <span class="wk-row-count">${it.count} ${pluralSubmissions(it.count)}</span>
        <span class="wk-row-date">${it.latest_at ? esc(fmtDateTime(it.latest_at)) : '—'}</span>
        <button type="button" class="prof-btn prof-btn-sm">Открыть</button>
    </div>`;
}

function pluralSubmissions(n) {
    const mod10 = n % 10, mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) { return 'сдача'; }
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) { return 'сдачи'; }
    return 'сдач';
}

function stepTwoHtml() {
    const backRow = `<div class="wk-step-head">
        <button type="button" class="wk-back">‹ К списку работ</button>
        <div class="wk-step-title">${esc(state.selected.title)}</div>
        <button type="button" class="prof-btn prof-btn-sm wk-refresh" data-refresh${state.refreshing ? ' disabled' : ''}>${state.refreshing ? 'Обновление…' : 'Обновить'}</button>
    </div>`;

    if (null === state.submissions) {
        return backRow + '<div class="wk-loading">Загрузка…</div>';
    }

    if (PASSED === state.selected.source_type) { return backRow + passedStudentsHtml(); }

    const rows = state.submissions.length
        ? `<div class="wk-sub-list">${state.submissions.map(subRow).join('')}</div>`
        : '<div class="wk-empty-wrap"><div class="prof-ktp-empty"><p>Сдач нет.</p></div></div>';

    return backRow + rows;
}

/* «Прошедшие», шаг 2: ученики занятия. Сдавший — обычная карточка сдачи (открывается; его сдача
   приоритетнее зачёта), не сдавший — «Засчитать» (максимальный балл). Несдавшим сразу всем — кнопка в шапке. */
function passedStudentsHtml() {
    const students = state.submissions;
    if (!students.length) {
        return '<div class="wk-empty-wrap"><div class="prof-ktp-empty"><p>В группе нет активных учеников.</p></div></div>';
    }

    const missing = students.filter((st) => !st.submission_id);
    const all = missing.length > 1
        ? `<div class="wk-credit-all"><button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-credit-all>Засчитать всем несдавшим (${missing.length})</button></div>`
        : '';

    return `${all}<div class="wk-sub-list">${students.map((st) => st.submission_id
        ? workCardHtml({
            title:      st.student_name,
            badge:      state.selected.badge,
            subtitle:   null === st.score ? '' : `${st.score} из ${st.max_score}`,
            sourceType: 'submission',
            sourceId:   st.submission_id,
            rowClass:   'wk-sub-row',
            // Засчитано вручную — можно отменить зачёт; настоящую сдачу ученика только открывают.
            action:     st.credited ? `<button type="button" class="prof-btn prof-btn-sm" data-uncredit="${st.student_person_id}">Отменить зачёт</button>` : '',
        })
        : `<div class="wcard wcard--static wk-credit-row">
            <span class="wcard-type">${badgeHtml(state.selected.badge)}</span>
            <div class="wcard-main"><span class="wcard-title">${esc(st.student_name)}</span><span class="wcard-sub">Нет сдачи</span></div>
            <button type="button" class="prof-btn prof-btn-sm" data-credit="${st.student_person_id}">Засчитать</button>
        </div>`).join('')}</div>`;
}

/** «Засчитать» одному или всем несдавшим: подтверждение → зачёт → перезагрузка списка. */
async function creditStudents(personIds) {
    const item = state.selected;
    const ok = await confirmDialog(
        'За каждое задание работы будет выставлен максимальный балл. Если ученик уже сдал работу, его сдача останется как есть.',
        'Засчитать',
        'Отмена'
    );
    if (!ok) { return; }
    try {
        const res = await api('creditWork', { group_lesson_id: item.group_lesson_id, work_id: item.work_id, person_ids: personIds });
        toast(res.credited ? 'Работа засчитана' : 'Засчитывать нечего');
    } catch (e) {
        toast(e.message || 'Не удалось засчитать работу.', 'error');
        return;
    }
    refreshWorks();
}

/** «Отменить зачёт» у одного ученика: работа снова числится несданной. */
async function uncreditStudent(personId) {
    const item = state.selected;
    const ok = await confirmDialog('Зачёт будет снят, ученик снова станет несдавшим эту работу.', 'Отменить зачёт', 'Назад');
    if (!ok) { return; }
    try {
        await api('uncreditWork', { group_lesson_id: item.group_lesson_id, work_id: item.work_id, person_id: personId });
        toast('Зачёт снят');
    } catch (e) {
        toast(e.message || 'Не удалось снять зачёт.', 'error');
        return;
    }
    refreshWorks();
}

/* Карточка сдачи (Tasks.md, п. 7) — общая вёрстка с «Сводкой по ученику»:
   бейдж типа, ФИО, полоска вердиктов по заданиям, справа время сдачи. */
function subRow(s) {
    return workCardHtml({
        title:      s.student_name,
        badge:      s.badge,
        marks:      s.marks,
        subtitle:   s.group_name,
        date:       s.submitted_at,
        duration:   s.duration_sec,
        sourceType: s.source_type,
        sourceId:   s.source_id,
        rowClass:   'wk-sub-row',
    });
}

/* ── Wiring ───────────────────────────────────────────────────────────── */
function wireTabs() {
    root.querySelectorAll('[data-tab]').forEach((btn) =>
        btn.addEventListener('click', () => {
            if (btn.dataset.tab !== state.tab) { loadTab(btn.dataset.tab); }
        }));
}

function wireStepOne() {
    root.querySelector('[data-refresh]')?.addEventListener('click', refreshWorks);
    const gBtn = root.querySelector('#wkGroupBtn');
    if (gBtn) {
        gBtn.addEventListener('click', () => {
            openGroupPicker(gBtn, state.groups, state.filterGroupId, (id) => {
                state.filterGroupId = id;
                render();
            }, [ { v: '0', label: 'Все группы', chip: '∀' } ]);
        });
    }

    const typeSel = root.querySelector('#wkTypeFilter');
    if (typeSel) {
        typeSel.addEventListener('change', () => { state.filterType = typeSel.value; render(); });
    }

    const sortSel = root.querySelector('#wkSort');
    if (sortSel) {
        sortSel.addEventListener('change', () => { state.sort = sortSel.value; render(); });
    }

    root.querySelectorAll('.wk-row[data-src-id]').forEach((el) =>
        el.addEventListener('click', () => {
            const item = (state.items || []).find(
                (it) => it.source_type === el.dataset.srcType && String(it.source_id) === el.dataset.srcId
            );
            if (item) { loadSubmissions(item); }
        }));
}

function wireStepTwo() {
    root.querySelector('[data-refresh]')?.addEventListener('click', refreshWorks);
    const back = root.querySelector('.wk-back');
    if (back) {
        back.addEventListener('click', () => {
            ++submissionsRequest;
            state.selected = null;
            state.submissions = null;
            render();
        });
    }

    root.querySelectorAll('.wk-sub-row[data-src-id]').forEach((el) => {
        el.addEventListener('click', () => {
            if (openWorkReviewCb) { openWorkReviewCb(el.dataset.srcType, +el.dataset.srcId); }
        });
        // Пока курсор над строкой, деталь работы уже грузится: к клику она обычно в кеше.
        const warm = () => prefetchWorkReview(el.dataset.srcType, +el.dataset.srcId);
        el.addEventListener('pointerenter', warm);
        el.addEventListener('focus', warm);
    });

    root.querySelectorAll('[data-credit]').forEach((btn) =>
        btn.addEventListener('click', () => creditStudents([+btn.dataset.credit])));
    root.querySelectorAll('[data-uncredit]').forEach((btn) =>
        btn.addEventListener('click', (e) => { e.stopPropagation(); uncreditStudent(+btn.dataset.uncredit); }));
    root.querySelector('[data-credit-all]')?.addEventListener('click', () => creditStudents([]));
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function empty(title, text) {
    return emptyState('prof-works', icoInbox(34), title, text);
}
