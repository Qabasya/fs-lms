/* ══════════════════════════════════════════════════════════════════════
   Экран «Работы» (D3, .docs/Tasks.md) — один список работ прошедших занятий
   групп преподавателя, всегда сначала новые (по дате занятия).

   Список грузится порциями: первые работы сразу, следующие — когда нижний край
   списка подходит к экрану. Фильтры — группа, тип работы и быстрый статус
   («Все / Ждут проверки / Есть несдавшие») — считает сервер, иначе порция после
   клиентского фильтра оказывалась бы неполной.

   Шаг 1 — список работ; шаг 2 — ученики выбранной работы: сдавший открывается на
   экран проверки (D2), не сдавшему можно «Засчитать» (максимальный балл).
   Экзамены и контрольные в этот список не входят.

   Источник: window.fsProfile.{groups, works:{nonce,actions}}.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, emptyState, fmtDate, toast } from './utils.js';
import { icoInbox } from '../common/icons.js';
import { createApi, clearApiCache } from './api.js';
import { prefetchWorkReview } from './work-review.js';
import { groupPickerBtnHtml, openGroupPicker, optionPickerBtnHtml, openOptionPicker } from './picker.js';
import { badgeHtml, workCardHtml } from './work-card.js';
import { confirmDialog } from '../common/components/confirm-dialog.js';
import { readNav, saveNav } from './nav-state.js';

/** Быстрый фильтр списка: ключ = параметр `status` запроса (PassedWorksService::STATUS_*). */
const STATUS_FILTERS = [
    { key: '', label: 'Все' },
    { key: 'pending', label: 'Ждут проверки' },
    { key: 'missing', label: 'Есть несдавшие' },
];

/** Тип работы: значение = Inc\Enums\Course\WorkType. */
const WORK_TYPE_OPTIONS = [
    { value: '', label: 'Все типы работ' },
    { value: 'practice', label: 'Практика' },
    { value: 'independent', label: 'Самостоятельная работа' },
    { value: 'homework', label: 'Домашнее задание' },
    { value: 'survey', label: 'Опрос' },
];

/** Статусы сдачи, при которых работа ждёт проверки (PassedWorksService::pendingStatuses). */
const PENDING_STATUSES = ['submitted', 'pending_review'];

let root = null;
let api = null;
let openWorkReviewCb = null;
let observer = null;

let state = null;
let listRequest = 0;
let studentsRequest = 0;

/** @param {{ openWorkReview?: (sourceType: string, sourceId: number) => void }} [opts] */
export function renderWorks(r, opts = {}) {
    root = r;
    openWorkReviewCb = typeof opts.openWorkReview === 'function' ? opts.openWorkReview : null;
    const p = window.fsProfile || {};
    // Фильтры из адреса (nav-state.js): «Назад» из экрана проверки возвращает к тем же фильтрам.
    const nav = readNav('works');
    state = {
        groups:       Array.isArray(p.groups) ? p.groups : [],
        cfg:          p.works || null,
        filterGroupId: Number(nav.g) || 0, // 0 = все группы
        filterType:   WORK_TYPE_OPTIONS.some((o) => o.value && o.value === nav.t) ? nav.t : '',
        filterStatus: STATUS_FILTERS.some((s) => s.key && s.key === nav.s) ? nav.s : '',
        items:        [],    // загруженные порции, новые сверху
        next:         null,  // метка продолжения; null — список закончился
        loaded:       false, // первая порция получена (или не получилась)
        loading:      false,
        error:        '',
        pendingTotal: null,  // сколько сдач ждут проверки (число у фильтра)
        selected:     null,  // выбранная работа (шаг 2)
        students:     null,
        refreshing:   false,
        scrollTop:    0,
    };
    // Порции и ученики — короткий кеш: возврат к работе не ждёт сервер;
    // «Обновить» и любая запись (зачёт) сбрасывают его.
    api = createApi(state.cfg, { cache: {
        getPassedWorks:    30000,
        getPassedStudents: 30000,
    } });
    if (!state.cfg) { root.innerHTML = empty('Проверка недоступна', 'Экран «Работы» не настроен.'); return; }

    loadFirstPage();
}

/**
 * Открывает список с быстрым фильтром по статусу (ссылка с главной «Работы без проверки»).
 *
 * @param {string} status '' | 'pending' | 'missing'
 */
export function applyWorksStatus(status) {
    if (!state || !state.cfg) { return; }
    const key = STATUS_FILTERS.some((s) => s.key === status) ? status : '';
    if (key === state.filterStatus && !state.selected) { return; }
    state.filterStatus = key;
    loadFirstPage();
}

/* ── Data ─────────────────────────────────────────────────────────────── */
function rowKey(it) { return `${it.group_lesson_id}:${it.work_id}`; }

/** Первая порция под текущие фильтры: прежний список и выбранная работа сбрасываются. */
async function loadFirstPage() {
    const requestId = ++listRequest;
    ++studentsRequest;
    state.items = [];
    state.next = null;
    state.loaded = false;
    state.loading = true;
    state.error = '';
    state.selected = null;
    state.students = null;
    render();
    await fetchPage('', requestId);
}

/** Следующая порция (по метке из прошлой). */
async function loadMore() {
    if (state.loading || !state.next) { return; }
    const requestId = ++listRequest;
    state.loading = true;
    state.error = '';
    renderListArea();
    await fetchPage(state.next, requestId);
}

async function fetchPage(cursor, requestId) {
    try {
        const page = await api('getPassedWorks', {
            group_id: state.filterGroupId,
            type:     state.filterType,
            status:   state.filterStatus,
            cursor,
        });
        if (requestId !== listRequest) { return; }
        // Данные могли сместиться между порциями (новое занятие, зачёт): повторов в списке не держим.
        const known = new Set(state.items.map(rowKey));
        state.items.push(...(page.items || []).filter((it) => !known.has(rowKey(it))));
        state.next = page.next || null;
        if (null !== page.pending_total && undefined !== page.pending_total) { state.pendingTotal = page.pending_total; }
    } catch (e) {
        if (requestId !== listRequest) { return; }
        state.error = e.message || 'Не удалось загрузить список работ.';
        if (state.refreshing) { toast(state.error, 'error'); }
    }
    state.loading = false;
    state.loaded = true;
    if (cursor) { renderListArea(); } else { render(); }
}

/** Ученики выбранной работы; `keep` — перечитать на месте, не уходя с экрана и не теряя прокрутку. */
async function loadStudents(item, keep = false) {
    const requestId = ++studentsRequest;
    if (!keep) {
        state.scrollTop = scrollPosition();
        state.selected = item;
        state.students = null;
        render();
        setScrollPosition(0);
    }
    try {
        const students = await api('getPassedStudents', { group_lesson_id: item.group_lesson_id, work_id: item.work_id });
        if (requestId !== studentsRequest) { return; }
        state.students = students;
        patchSelected(students);
    } catch (e) {
        if (requestId !== studentsRequest) { return; }
        if (!keep) { state.students = []; }
        toast(e.message || 'Не удалось загрузить учеников.', 'error');
    }
    render();
}

/** Счётчики выбранной работы в списке — по свежему составу учеников (список заново не грузим). */
function patchSelected(students) {
    const it = state.selected;
    if (!it) { return; }
    const submitted = students.filter((st) => st.submission_id).length;
    it.total = students.length;
    it.submitted = submitted;
    it.missing = students.length - submitted;
    it.pending = students.filter((st) => PENDING_STATUSES.includes(st.status)).length;
}

/** «Обновить»: сброс кеша и перезагрузка — списка с первой порции либо учеников открытой работы. */
async function refresh() {
    if (state.refreshing) { return; }
    clearApiCache();
    state.refreshing = true;
    render(); // «Обновление…» сразу, не дожидаясь ответа сервера
    try {
        if (state.selected) { await loadStudents(state.selected, true); } else { await loadFirstPage(); }
    } finally {
        state.refreshing = false;
        render();
    }
}

/* ── Scroll ───────────────────────────────────────────────────────────── */
/** Прокручивается секция экрана кабинета, а не окно. */
function scroller() { return root && typeof root.closest === 'function' ? root.closest('.prof-screen') : null; }
function scrollPosition() { return scroller()?.scrollTop || 0; }
function setScrollPosition(top) { const el = scroller(); if (el) { el.scrollTop = top; } }

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    observer?.disconnect();
    saveNav('works', { g: state.filterGroupId, t: state.filterType, s: state.filterStatus });
    root.innerHTML = `
    <div class="prof-works">
        <div class="prof-swap">${state.selected ? stepTwoHtml() : stepOneHtml()}</div>
    </div>`;

    if (state.selected) { wireStepTwo(); } else { wireStepOne(); }
}

/** Только область списка: подгрузка новой порции не пересобирает фильтры и не сдвигает прокрутку. */
function renderListArea() {
    const area = root.querySelector('#wkListArea');
    if (!area) { return; }
    observer?.disconnect();
    area.innerHTML = listAreaHtml();
    wireListArea();
}

function stepOneHtml() {
    return `${filtersHtml()}<div id="wkListArea">${listAreaHtml()}</div>`;
}

function filtersHtml() {
    const g = state.groups.find((x) => x.id === state.filterGroupId);
    const statuses = STATUS_FILTERS.map((s) => {
        const badge = 'pending' === s.key && state.pendingTotal ? `<span class="wk-badge">${state.pendingTotal}</span>` : '';
        return `<button type="button" class="${s.key === state.filterStatus ? 'on' : ''}" data-status="${s.key}">${esc(s.label)}${badge}</button>`;
    }).join('');

    const type = WORK_TYPE_OPTIONS.find((o) => o.value === state.filterType) || WORK_TYPE_OPTIONS[0];
    return `<div class="wk-filters">
        <div class="wk-filter">
            ${groupPickerBtnHtml(g || { id: 0, name: 'Все группы', subject: '' }, 'wkGroupBtn')}
        </div>
        <div class="wk-filter">
            ${optionPickerBtnHtml(type.label, 'wkTypeBtn', 'Тип работы')}
        </div>
        <div class="prof-seg" role="group" aria-label="Статус работ">${statuses}</div>
        <button type="button" class="prof-btn prof-btn-sm wk-refresh" data-refresh${state.refreshing ? ' disabled' : ''}>${state.refreshing ? 'Обновление…' : 'Обновить'}</button>
    </div>`;
}

/** Работы, подходящие под быстрый статус: после зачёта счётчики меняются на месте, и строка может перестать подходить. */
function visibleItems() {
    return state.items.filter((it) => 'pending' === state.filterStatus ? it.pending > 0 : 'missing' === state.filterStatus ? it.missing > 0 : true);
}

function listAreaHtml() {
    if (!state.loaded && state.loading) {
        return '<div class="wk-loading">Загрузка…</div>';
    }

    const items = visibleItems();
    if (!items.length && !state.next) {
        return state.error
            ? `<div class="wk-loading">${esc(state.error)}<br><button type="button" class="prof-btn prof-btn-sm" data-more>Повторить</button></div>`
            : emptyState('wk-empty-wrap', icoInbox(34), emptyTitle(), emptyText());
    }

    let tail = '';
    if (state.loading) {
        tail = '<div class="wk-loading">Загрузка…</div>';
    } else if (state.error) {
        tail = `<div class="wk-more">${esc(state.error)} <button type="button" class="prof-btn prof-btn-sm" data-more>Повторить</button></div>`;
    } else if (state.next) {
        // Кнопка одновременно «метка» для подгрузки по прокрутке и ручной путь, если прокрутка недоступна.
        tail = '<div class="wk-more"><button type="button" class="prof-btn prof-btn-sm" data-more>Показать ещё</button></div>';
    }

    return `<div class="wk-list">${items.map(itemRow).join('')}</div>${tail}`;
}

function emptyTitle() {
    return 'pending' === state.filterStatus ? 'Работ на проверку нет'
        : 'missing' === state.filterStatus ? 'Несдавших нет'
        : 'Работ нет';
}

function emptyText() {
    return 'pending' === state.filterStatus ? 'Все сдачи проверены.'
        : 'missing' === state.filterStatus ? 'Все ученики сдали эти работы.'
        : 'Работ в прошедших занятиях пока нет.';
}

/* Работа занятия: тип, название, группа · тема, сколько сдали и сколько ждёт проверки, дата занятия. */
function itemRow(it) {
    const pending = it.pending ? `<span class="wk-row-pending">на проверке ${it.pending}</span>` : '';
    return `<div class="wk-row" data-key="${esc(rowKey(it))}">
        <span class="wk-type">${badgeHtml(it.badge)}</span>
        <span class="wk-row-title" title="${esc(it.title)}">${esc(it.title)}<span class="wk-row-sub">${esc([it.group_name, it.topic].filter(Boolean).join(' · '))}</span></span>
        <span class="wk-row-stats"><span>сдали ${it.submitted} из ${it.total}</span>${pending}</span>
        <span class="wk-row-date">${esc(fmtDate(it.date))}</span>
        <button type="button" class="prof-btn prof-btn-sm">Открыть</button>
    </div>`;
}

function stepTwoHtml() {
    const it = state.selected;
    const sub = [it.group_name, it.topic, fmtDate(it.date)].filter(Boolean).join(' · ');
    const head = `<div class="wk-step-head">
        <button type="button" class="wk-back">‹ К списку работ</button>
        <div class="wk-step-title">${esc(it.title)}<span class="wk-row-sub">${esc(sub)}</span></div>
        <button type="button" class="prof-btn prof-btn-sm wk-refresh" data-refresh${state.refreshing ? ' disabled' : ''}>${state.refreshing ? 'Обновление…' : 'Обновить'}</button>
    </div>`;

    if (null === state.students) {
        return head + '<div class="wk-loading">Загрузка…</div>';
    }

    return head + studentsHtml();
}

/* Ученики работы. Сдавший — обычная карточка сдачи (открывается; его сдача приоритетнее зачёта),
   не сдавший — «Засчитать» (максимальный балл). Несдавшим сразу всем — кнопка над списком. */
function studentsHtml() {
    const students = state.students;
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
            subtitle:   studentSubtitle(st),
            marks:      st.marks,
            date:       st.submitted_at,
            duration:   st.duration_sec,
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

/** Подпись под ФИО по статусу сдачи (оценка, «на проверке», зачёт…). */
function studentSubtitle(st) {
    const score = null === st.score ? '' : `${st.score} из ${st.max_score}`;
    if (st.credited) { return score ? `Засчитано · ${score}` : 'Засчитано'; }
    if (PENDING_STATUSES.includes(st.status)) { return 'На проверке'; }
    if ('returned' === st.status) { return 'Возвращена на доработку'; }
    if ('assigned' === st.status) { return 'Начата, не сдана'; }
    return score;
}

/** «Засчитать» одному или всем несдавшим: подтверждение → зачёт → перечитать учеников. */
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
    loadStudents(item, true);
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
    loadStudents(item, true);
}

/* ── Wiring ───────────────────────────────────────────────────────────── */
function wireStepOne() {
    root.querySelector('[data-refresh]')?.addEventListener('click', refresh);
    const gBtn = root.querySelector('#wkGroupBtn');
    if (gBtn) {
        gBtn.addEventListener('click', () => {
            openGroupPicker(gBtn, state.groups, state.filterGroupId, (id) => {
                state.filterGroupId = id;
                loadFirstPage();
            }, [ { v: '0', label: 'Все группы', chip: '∀', first: true } ]);
        });
    }

    const typeBtn = root.querySelector('#wkTypeBtn');
    if (typeBtn) {
        typeBtn.addEventListener('click', () => {
            openOptionPicker(typeBtn, WORK_TYPE_OPTIONS, state.filterType, (value) => {
                state.filterType = value;
                loadFirstPage();
            });
        });
    }

    root.querySelectorAll('[data-status]').forEach((btn) =>
        btn.addEventListener('click', () => {
            if (btn.dataset.status !== state.filterStatus) { state.filterStatus = btn.dataset.status; loadFirstPage(); }
        }));

    wireListArea();
}

/** Строки списка и «метка» подгрузки: элементы пересоздаются при каждой порции, поэтому вешаем заново. */
function wireListArea() {
    root.querySelectorAll('.wk-row[data-key]').forEach((el) =>
        el.addEventListener('click', () => {
            const item = state.items.find((it) => rowKey(it) === el.dataset.key);
            if (item) { loadStudents(item); }
        }));

    const more = root.querySelector('[data-more]');
    if (!more) { return; }
    more.addEventListener('click', () => {
        if (state.error && !state.items.length) { loadFirstPage(); return; }
        state.error = '';
        loadMore();
    });
    // Подгрузка по прокрутке: кнопка у нижнего края списка нажимается сама. После ошибки — только вручную.
    if (typeof IntersectionObserver === 'function' && !state.error) {
        observer = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) { loadMore(); }
        }, { rootMargin: '300px' });
        observer.observe(more);
    }
}

function wireStepTwo() {
    root.querySelector('[data-refresh]')?.addEventListener('click', refresh);
    const back = root.querySelector('.wk-back');
    if (back) {
        back.addEventListener('click', () => {
            ++studentsRequest;
            state.selected = null;
            state.students = null;
            render();
            setScrollPosition(state.scrollTop);
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
