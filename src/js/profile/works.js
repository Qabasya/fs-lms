/* ══════════════════════════════════════════════════════════════════════
   Вкладка «Работы» (D3, .docs/Tasks.md) — список работ/экзаменов на
   проверку, без обхода по ученикам (Сводка → группа → ученик → занятие).
   Три вкладки (На проверке / Ждут подтверждения / Проверенные) + фильтры
   группа/тип работы/сортировка. Шаг 1 — работы активной вкладки; шаг 2 —
   сдачи выбранной работы/экзамена → переход на work-review (D2).
   Источник: window.fsProfile.{groups, works:{nonce,actions}}.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDateTime, toast } from './utils.js';
import { icoInbox } from '../common/icons.js';
import { createApi } from './api.js';
import { groupPickerBtnHtml, openGroupPicker } from './picker.js';
import { workCardHtml } from './work-card.js';

const TABS = [
    { key: 'pending', label: 'На проверке' },
    { key: 'confirm', label: 'Ждут подтверждения' },
    { key: 'done', label: 'Проверенные' },
];

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
const countGenerations = { pending: 0, confirm: 0, done: 0 };
let countsSweep = 0;
let submissionsRequest = 0;

/** @param {{ openWorkReview?: (sourceType: string, sourceId: number) => void }} [opts] */
export function renderWorks(r, opts = {}) {
    root = r;
    openWorkReviewCb = typeof opts.openWorkReview === 'function' ? opts.openWorkReview : null;
    const p = window.fsProfile || {};
    state = {
        groups:     Array.isArray(p.groups) ? p.groups : [],
        cfg:        p.works || null,
        tab:        'pending',
        filterGroupId: 0, // 0 = все группы
        filterType: '',
        sort:       'new', // new | old
        items:      null,  // список работ активной вкладки (шаг 1)
        counts:     {},    // tab => суммарное число сдач в корзине
        selected:   null,  // выбранная работа (шаг 2)
        submissions: null,
        refreshing: false,
    };
    api = createApi(state.cfg);
    if (!state.cfg) { root.innerHTML = empty('Проверка недоступна', 'Экран «Работы» не настроен.'); return; }

    loadAllCounts();
    loadTab(state.tab);
}

/* ── Data ─────────────────────────────────────────────────────────────── */
async function loadAllCounts() {
    const sweepId = ++countsSweep;
    const versions = Object.fromEntries(TABS.map((t) => [t.key, ++countGenerations[t.key]]));
    await Promise.all(TABS.map(async (t) => {
        try {
            const items = await api('getPendingWorks', { tab: t.key });
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
        const items = await api('getPendingWorks', { tab });
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
        const submissions = await api('getWorkSubmissions', {
            source_type: item.source_type,
            source_id:   item.source_id,
            tab:         state.tab,
        });
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
            const items = await api('getPendingWorks', { tab: t.key });
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
                const items = await api('getWorkSubmissions', {
                    source_type: selected.source_type,
                    source_id: selected.source_id,
                    tab,
                });
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
        : emptyState('wk-empty-wrap', icoInbox(34), 'Работ на проверку нет', tabEmptyText());

    return `${filtersHtml()}${list}`;
}

function tabEmptyText() {
    return 'pending' === state.tab ? 'Все сдачи проверены.'
        : 'confirm' === state.tab ? 'Нет работ, ожидающих подтверждения.'
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

function itemRow(it) {
    return `<div class="wk-row" data-src-type="${esc(it.source_type)}" data-src-id="${it.source_id}">
        <span class="wk-type"><span class="wk-badge wk-badge-${it.source_type === 'assessment' ? 'exam' : 'work'}">${esc(it.label)}</span></span>
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

    const rows = state.submissions.length
        ? `<div class="wk-sub-list">${state.submissions.map(subRow).join('')}</div>`
        : '<div class="wk-empty-wrap"><div class="prof-ktp-empty"><p>Сдач нет.</p></div></div>';

    return backRow + rows;
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

    root.querySelectorAll('.wk-sub-row[data-src-id]').forEach((el) =>
        el.addEventListener('click', () => {
            if (openWorkReviewCb) { openWorkReviewCb(el.dataset.srcType, +el.dataset.srcId); }
        }));
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function empty(title, text) {
    return emptyState('prof-works', icoInbox(34), title, text);
}
