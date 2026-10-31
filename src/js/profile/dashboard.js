/* ══════════════════════════════════════════════════════════════════════
   Главная кабинета — реальные данные через AJAX (Эпик 6).
   Источник: window.fsProfile.dashboard:{nonce,actions}. Кросс-групповой агрегат:
   расписание сегодня/неделя/месяц, ворклист «заполнить»/«проверить», стат-плитки,
   маркеры замен (Эпик 5). Демо-слой (data.js) убран.

   Над расписанием — две плитки: «Занятий сегодня» и «На проверке». «Требует внимания»
   (с невыполненным: журналы не заполнены, работы без проверки, сигналы администратора)
   — аккордеон под расписанием; раскрытость запоминается в браузере преподавателя. Администратору в «Требует
   внимания» идут и его сигналы (worklist.alerts): журнал не заполнен спустя
   сутки, серии пропусков и несданных ДЗ, работы без проверки 48 часов.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, plural, fmtDayMonth, todayIso, chipBg, scheduleTone, groupSubjectKey, shortName, emptyState, roomLabel } from './utils.js';
import { icoCalendar, icoCheck, icoAlert, icoMapPin, icoBookmark, icoShield, icoChevronRight, icoChevronDown, icoHome, icoJournal, icoUsers, icoDocCheck } from '../common/icons.js';
import { createApi } from './api.js';
import { tilesHtml, arrowsHtml, editBarHtml, wireHomeEditor } from './home-customization.js';
import { persistHomeLayout } from './home-layout-preferences.js';
import { initSchedule, todayRowsHtml, scheduleCardHtml } from './schedule-view.js';

/** Ключ запомненной раскрытости аккордеонов главной. */
const ACC_KEY = 'fsProfDashAcc';

let root = null;
let state = null;
let api = null;
let homeEdit = false;
let homeLayout = null;
const DEFAULT_SECTIONS = ['tiles', 'schedule', 'attention'];

export function enterDashboardCustomize() {
    homeEdit = true;
    if (state?.data) render();
}

function saveLayout() {
    if (window.fsProfile?.homeLayout) persistHomeLayout(homeLayout);
}

let nav = { openJournalFor: () => {}, openReview: () => {}, openWorks: () => {}, openSummary: null, openExamConduct: null };

export function renderDashboard(r, handlers) {
    root = r;
    nav = Object.assign(nav, handlers || {});
    const p = window.fsProfile || {};
    state = { cfg: p.dashboard || null, data: null };
    if (!state.cfg) { root.innerHTML = emptyHtml('Главная недоступна', 'Нет данных кабинета.'); return; }
    api = createApi(state.cfg, { cache: { getDashboard: 30000 } });
    load();
}

async function load() {
    root.innerHTML = `<div class="prof-dash"><div class="rev-loading">Загрузка…</div></div>`;
    try {
        state.data = await api('getDashboard', {});
    } catch (e) {
        root.innerHTML = emptyHtml('Не удалось загрузить', e.message);
        return;
    }
    render();
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const d = state.data;
    const s = d.stats;
    const name = window.fsProfile?.user?.name || 'преподаватель';
    const alerts = d.worklist.alerts || [];
    const attnCount = alerts.length + d.worklist.to_fill.length + d.worklist.to_review.length;

    homeLayout ??= { sections: [...(d.home_layout?.sections || DEFAULT_SECTIONS)], tiles: d.home_layout?.tiles ?? null };
    const catalog = [
        { id: 'lessons_today', label: 'Занятий сегодня', value: String(s.lessons_today), note: `${s.groups} ${plural(s.groups, 'группа', 'группы', 'групп')}${s.individual ? ` · ${s.individual} инд.` : ''}`, color: '#3b5bdb', ico: 'cal' },
        { id: 'to_review', label: 'На проверке', value: String(s.to_review), note: 'работ ждут оценки', color: '#7048e8', ico: 'check' },
    ];
    const sections = {
        tiles: tilesHtml(catalog, ['lessons_today', 'to_review'], homeLayout, homeEdit, false),
        schedule: scheduleCardHtml('data-home-section="schedule"'),
        attention: `<div data-home-section="attention">${homeEdit ? arrowsHtml() : ''}${accordion('attention', 'Требует внимания', `${attnCount} ${plural(attnCount, 'задача', 'задачи', 'задач')}`, `
            ${alerts.map(alertRow).join('')}
            ${d.worklist.to_fill.map(fillRow).join('')}
            ${d.worklist.to_review.map(reviewRow).join('')}
            ${attnCount ? '' : '<div class="rev-empty">Всё в порядке — журналы заполнены, работы проверены.</div>'}`, icoAlert)}</div>`,
    };
    root.innerHTML = `
    <div class="prof-dash">
        <div class="prof-dash-hello">
            <h1>Здравствуйте,<span class="prof-hello-name"> ${esc(name)}</span></h1>
            <p>Сегодня ${s.lessons_today} ${plural(s.lessons_today, 'занятие', 'занятия', 'занятий')} · ${s.to_review} ${plural(s.to_review, 'работа', 'работы', 'работ')} на проверке</p>
        </div>

        ${d.covering.length ? coveringBanner(d.covering) : ''}

        ${homeEdit ? editBarHtml() : ''}
        <div class="prof-dash-grid2${homeEdit ? ' is-editing' : ''}">${homeLayout.sections.map(id => sections[id] || '').join('')}</div>
    </div>`;

    if (homeEdit) {
        wireHomeEditor(root, { layout: homeLayout, catalog, defaultTiles: ['lessons_today', 'to_review'], defaultSections: DEFAULT_SECTIONS,
            save: saveLayout, redraw: render, done: () => { homeEdit = false; render(); } });
    }

    initSchedule({
        toggle:    root.querySelector('#profSchedToggle'),
        body:      root.querySelector('#profSchedBody'),
        // Сеансы экзаменов — такие же события расписания: в «Сегодня», «Неделе» и «Месяце» рядом с занятиями.
        items:     [...d.week, ...examItems()],
        todayHtml: () => {
            const todayItems = [...d.today, ...examItems().filter(x => x.date === localToday())]
                .sort((a, b) => String(a.start).localeCompare(String(b.start)));
            return todayItems.length
                ? todayRowsHtml(todayItems, schedRow)
                : `<div class="rev-empty">Сегодня занятий нет.</div>`;
        },
        cardHtml:  lessonCard,
    });

    root.querySelectorAll('.prof-acc[data-acc]').forEach(acc => {
        const head = acc.querySelector('.prof-acc-head');
        head.addEventListener('click', () => {
            const open = acc.classList.toggle('is-open');
            head.setAttribute('aria-expanded', String(open));
            saveAccOpen(acc.dataset.acc, open);
        });
    });

    // НБ-11: расписание перерисовывается (смена режима, пагинация недель) —
    // делегируем клик на контейнере, чтобы переходы в журнал переживали ре-рендер.
    const schedBody = root.querySelector('#profSchedBody');
    if (schedBody) schedBody.addEventListener('click', e => {
        const examEl = e.target.closest('[data-exam-session]');
        if (examEl) {
            // Сеанс экзамена ведёт в «Проведение экзамена» этого сеанса (4.7).
            if (nav.openExamConduct) nav.openExamConduct(+examEl.dataset.examSession);
            return;
        }
        const el = e.target.closest('[data-grp]');
        if (el) nav.openJournalFor(el.dataset.grp);
    });

    root.querySelectorAll('.prof-work-item[data-grp]').forEach(el =>
        el.addEventListener('click', () => nav.openJournalFor(el.dataset.grp)));
    root.querySelectorAll('[data-review]').forEach(el =>
        el.addEventListener('click', () => nav.openReview()));
    root.querySelectorAll('[data-alert-works]').forEach(el =>
        el.addEventListener('click', () => nav.openWorks()));
    root.querySelectorAll('[data-alert-pid]').forEach(el =>
        el.addEventListener('click', () => {
            if (nav.openSummary) nav.openSummary(+el.dataset.alertGrp, +el.dataset.alertPid);
            else nav.openReview();
        }));
}

/**
 * Карточка занятия в «Неделе» и «Месяце»: 1) время, группа, кабинет;
 * 2) преподаватель («Сахаров Д.С.») и метки замены/инд.; 3) тема — сколько влезет.
 */
function lessonCard(it, cls) {
    // Сеанс экзамена — самостоятельное событие, не занятие группы: своя метка и переход в «Проведение экзамена».
    if (it.kind === 'exam') {
        return `<div class="${cls} lcard ${scheduleTone('exam')}" data-exam-session="${it.session_id}">
            <div class="lc-row">
                <span class="lc-time">${esc(it.start)}</span>
                <span class="lc-grp">${esc(it.title)}</span>
                ${roomLabel(it.room) ? `<span class="lc-room">${esc(roomLabel(it.room))}</span>` : ''}
            </div>
            <div class="lc-row lc-teacher"><span class="prof-chip">Экзамен</span></div>
            <div class="lc-topic">записано ${it.occupied} из ${it.capacity}</div>
        </div>`;
    }

    const who  = it.kind === 'individual' && it.student_name ? it.student_name : it.group_name;
    const tags = (it.is_substitute ? '<span class="prof-sub-tag">замена</span>' : '')
        + (it.kind === 'individual' ? '<span class="prof-sub-tag indi">инд.</span>' : '');

    return `<div class="${cls} lcard ${scheduleTone(it.kind, groupSubjectKey(it.group_id))}" data-grp="${it.group_id}">
        <div class="lc-row">
            <span class="lc-time">${esc(it.start)}</span>
            <span class="lc-grp">${esc(who)}</span>
            ${roomLabel(it.room) ? `<span class="lc-room">${esc(roomLabel(it.room))}</span>` : ''}
        </div>
        ${it.teacher || tags ? `<div class="lc-row lc-teacher"><span class="lc-name">${esc(it.teacher || '')}</span>${tags}</div>` : ''}
        <div class="lc-topic" title="${esc(it.topic || '')}">${esc(it.topic || '—')}</div>
    </div>`;
}

/**
 * Карточка-аккордеон главной. Изначально свёрнута, дальше — как оставил
 * преподаватель.
 */
function accordion(key, title, sub, bodyHtml, icon = null) {
    const open = true === readAccOpen()[key];

    return `<div class="prof-card prof-acc${open ? ' is-open' : ''}" data-acc="${key}">
        <button type="button" class="prof-card-head prof-acc-head" aria-expanded="${open}" aria-controls="profAcc-${key}">
            ${icon ? `<span class="prof-acc-ico">${icon(16)}</span>` : ''}
            <h3>${esc(title)}</h3>
            <span class="ch-sub">${esc(sub)}</span>
            <span class="prof-acc-chev">${icoChevronDown(14)}</span>
        </button>
        <div class="prof-acc-body prof-fold" id="profAcc-${key}">
            <div class="prof-fold-inner">${bodyHtml}</div>
        </div>
    </div>`;
}

function readAccOpen() {
    try { return JSON.parse(localStorage.getItem(ACC_KEY) || '{}') || {}; } catch { return {}; }
}

function saveAccOpen(key, open) {
    try { localStorage.setItem(ACC_KEY, JSON.stringify(Object.assign(readAccOpen(), { [key]: open }))); } catch { /* браузер без хранилища — просто не запомним */ }
}

/** Сеансы экзаменов в виде элементов расписания: `start`/`end` — как у занятий, чтобы сортировались вместе. */
function examItems() {
    return (state.data.exams || []).map(x => ({ ...x, kind: 'exam', start: x.time_start, end: x.time_end }));
}

/** Сегодняшняя дата по часам пользователя (`Y-m-d`): сеанс экзамена приходит в местном времени сайта. */
function localToday() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function schedRow(l) {
    if (l.kind === 'exam') return examRow(l);
    const stateMap = {
        now:  '<span class="prof-state-pill prof-state-now">Идёт сейчас</span>',
        soon: '<span class="prof-state-pill prof-state-soon">Скоро</span>',
        done: '<span class="prof-state-pill prof-state-done">Завершён</span>',
    };
    const inner = `
        <div class="prof-lesson-time">
            <div class="lt-start">${esc(l.start)}</div>
            <div class="lt-end">${esc(l.end || '')}</div>
        </div>
        <div class="prof-lesson-bar ${l.kind === 'individual' ? 'chip-indi' : chipBg(groupSubjectKey(l.group_id))}"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(l.kind === 'individual' && l.student_name ? l.student_name : l.group_name)}${l.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : ''}${l.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : ''}</div>
            <div class="prof-lesson-topic">${esc(l.topic || '—')}</div>
            <div class="prof-lesson-meta"><span class="lm">${esc(l.subject)}</span>${l.room ? `<span class="lm">${icoMapPin(13)}${esc(l.room)}</span>` : ''}</div>
        </div>
        <div class="prof-lesson-state">${stateMap[l.state] || ''}</div>`;
    // Этап 2 (★): занятие с контентом ведёт в плеер курса (teacher-режим), как и у
    // ученика (learner.js); без контента — прежнее поведение (клик открывает журнал).
    if (l.player_url) {
        return `<a class="prof-lesson-row prof-lesson-go ${l.state === 'now' ? 'is-now' : ''}" href="${esc(l.player_url)}">${inner}</a>`;
    }
    return `<div class="prof-lesson-row ${l.state === 'now' ? 'is-now' : ''}" data-grp="${l.group_id}">${inner}</div>`;
}

/* Строка сеанса экзамена в «Сегодня»: та же разметка, что у занятия, с пометкой «Экзамен»; клик — в «Проведение экзамена». */
function examRow(x) {
    const stateMap = {
        now:  '<span class="prof-state-pill prof-state-now">Идёт сейчас</span>',
        soon: '<span class="prof-state-pill prof-state-soon">Скоро</span>',
        done: '<span class="prof-state-pill prof-state-done">Завершён</span>',
    };
    return `<div class="prof-lesson-row ${x.state === 'now' ? 'is-now' : ''}" data-exam-session="${x.session_id}" role="button" tabindex="0">
        <div class="prof-lesson-time">
            <div class="lt-start">${esc(x.start)}</div>
            <div class="lt-end">${esc(x.end || '')}</div>
        </div>
        <div class="prof-lesson-bar ${chipBg('exam')}"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(x.title)} <span class="prof-chip">Экзамен</span></div>
            <div class="prof-lesson-topic">записано ${x.occupied} из ${x.capacity}</div>
            <div class="prof-lesson-meta">${x.room ? `<span class="lm">${icoMapPin(13)}${esc(x.room)}</span>` : ''}</div>
        </div>
        <div class="prof-lesson-state">${stateMap[x.state] || ''}</div>
    </div>`;
}

/* Сигнал администратору: журнал → в журнал группы, ученик → в его сводку,
   непроверенная работа → в «Работы». */
function alertRow(a) {
    const n = +a.count || 0;
    const view = {
        journal: {
            attrs: `data-grp="${a.group_id}"`,
            ico: ['att', icoJournal(18)],
            title: `Преподаватель не заполнил журнал · ${esc(a.group_name)}`,
            sub: [a.teacher_name, a.topic, a.date ? fmtDayMonth(a.date) : ''].filter(Boolean).map(esc).join(' · '),
        },
        absence: {
            attrs: `data-alert-grp="${a.group_id}" data-alert-pid="${a.person_id}"`,
            ico: ['att', icoUsers(18)],
            title: `Ученик не посещает занятия · ${esc(a.group_name)}`,
            sub: `${esc(a.student_name)} — пропущено подряд: ${n}`,
        },
        homework: {
            attrs: `data-alert-grp="${a.group_id}" data-alert-pid="${a.person_id}"`,
            ico: ['att', icoAlert(18)],
            title: `Ученик не сдаёт работы · ${esc(a.group_name)}`,
            sub: `${esc(a.student_name)} — не сдано подряд ДЗ: ${n}`,
        },
        review: {
            attrs: 'data-alert-works="1"',
            ico: ['grade', icoDocCheck(18)],
            title: `Работа не проверена 48 часов · ${esc(a.group_name)}`,
            sub: `${esc(a.teacher_name)}: ${esc(a.student_name)}${a.topic ? ` — «${esc(a.topic)}»` : ''}`,
        },
    }[a.kind];
    if (!view) return '';

    return `<div class="prof-work-item is-clickable" ${view.attrs}>
        <div class="prof-work-ico ${view.ico[0]}">${view.ico[1]}</div>
        <div class="prof-work-main">
            <div class="prof-work-title">${view.title}</div>
            <div class="prof-work-sub">${view.sub}</div>
        </div>
        ${n ? `<span class="prof-work-count">${n}</span>` : ''}
        ${icoChevronRight(18, 'var(--muted-2)')}
    </div>`;
}

function fillRow(w) {
    return `<div class="prof-work-item is-clickable" data-grp="${w.group_id}">
        <div class="prof-work-ico att">${icoCalendar(18)}</div>
        <div class="prof-work-main">
            <div class="prof-work-title">Заполнить посещаемость · ${esc(w.group_name)}</div>
            <div class="prof-work-sub">${esc(w.topic || '—')} · ${fmtDayMonth(w.date)}</div>
        </div>
        <span class="prof-work-count">${w.missing}</span>
        ${icoChevronRight(18, 'var(--muted-2)')}
    </div>`;
}

function reviewRow(w) {
    return `<div class="prof-work-item is-clickable" data-review="1">
        <div class="prof-work-ico grade">
            ${icoBookmark(18)}
        </div>
        <div class="prof-work-main">
            <div class="prof-work-title">Проверить работы · ${esc(w.group_name)}</div>
            <div class="prof-work-sub">в очереди на проверку</div>
        </div>
        <span class="prof-work-count">${w.count}</span>
        ${icoChevronRight(18, 'var(--muted-2)')}
    </div>`;
}

function coveringBanner(covering) {
    const today = todayIso();
    return `<div class="prof-cover-banner">
        ${icoShield(18)}
        <span>Вы замещаете: ${covering.map(c => c.valid_from > today
            ? `${esc(c.group_name)} <b>с ${fmtDayMonth(c.valid_from)}</b>`
            : `${esc(c.group_name)} <b>до ${fmtDayMonth(c.valid_to)}</b>`).join(', ')}</span>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function emptyHtml(title, text) {
    return emptyState('prof-dash', icoHome(34), title, text);
}
