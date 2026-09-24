/* ══════════════════════════════════════════════════════════════════════
   Главная кабинета — реальные данные через AJAX (Эпик 6).
   Источник: window.fsProfile.dashboard:{nonce,actions}. Кросс-групповой агрегат:
   расписание сегодня/неделя/месяц, ворклист «заполнить»/«проверить», стат-плитки,
   маркеры замен (Эпик 5). Демо-слой (data.js) убран.

   «Требует внимания» и «Мои группы» — аккордеоны над расписанием; раскрытость
   каждого запоминается в браузере преподавателя.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, plural, fmtDayMonth, todayIso, chipBg, chipBorder, groupSubjectKey, shortName, emptyState } from './utils.js';
import { icoCalendar, icoCheck, icoAlert, icoMapPin, icoBookmark, icoShield, icoChevronRight, icoChevronDown, icoHome } from '../common/icons.js';
import { createApi } from './api.js';
import { DOW_JS, DOW_RU } from './constants.js';

/** Сколько недель показывает режим «Месяц» (по ряду на неделю). */
const MONTH_WEEKS = 4;

/** Ключ запомненной раскрытости аккордеонов главной. */
const ACC_KEY = 'fsProfDashAcc';

let root = null;
let state = null;
let api = null;
let nav = { openJournalFor: () => {}, openReview: () => {} };

export function renderDashboard(r, handlers) {
    root = r;
    nav = Object.assign(nav, handlers || {});
    const p = window.fsProfile || {};
    state = { cfg: p.dashboard || null, data: null, weekOffset: 0, monthOffset: 0 };
    if (!state.cfg) { root.innerHTML = emptyHtml('Главная недоступна', 'Нет данных кабинета.'); return; }
    api = createApi(state.cfg);
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
    const attnCount = d.worklist.to_fill.length + d.worklist.to_review.length;

    root.innerHTML = `
    <div class="prof-dash">
        <div class="prof-dash-hello">
            <h1>Здравствуйте, ${esc(name)} 👋</h1>
            <p>Сегодня ${s.lessons_today} ${plural(s.lessons_today, 'занятие', 'занятия', 'занятий')} · ${s.to_review} ${plural(s.to_review, 'работа', 'работы', 'работ')} на проверке</p>
        </div>

        ${d.covering.length ? coveringBanner(d.covering) : ''}

        <div class="prof-stat-tiles">
            ${statTile('Занятий сегодня', String(s.lessons_today), `${s.groups} ${plural(s.groups, 'группа', 'группы', 'групп')}${s.individual ? ` · ${s.individual} инд.` : ''}`, '#3b5bdb', 'cal')}
            ${statTile('На проверке', String(s.to_review), 'работ ждут оценки', '#7048e8', 'check')}
            ${statTile('Не заполнено', String(s.to_fill), 'журналов посещаемости', '#e03131', 'alert')}
        </div>

        <div class="prof-dash-grid2">
            ${accordion('attention', 'Требует внимания', `${attnCount} ${plural(attnCount, 'задача', 'задачи', 'задач')}`, `
                ${d.worklist.to_fill.map(fillRow).join('')}
                ${d.worklist.to_review.map(reviewRow).join('')}
                ${attnCount ? '' : '<div class="rev-empty">Всё в порядке — журналы заполнены, работы проверены.</div>'}`)}
            ${accordion('groups', 'Мои группы', `${d.groups.length} ${plural(d.groups.length, 'группа', 'группы', 'групп')}`,
                d.groups.length ? d.groups.map(grpCard).join('') : '<div class="rev-empty">Нет групп.</div>')}
        </div>

        <div class="prof-card prof-sched-card">
            <div class="prof-card-head">
                <h3>Расписание</h3>
                <div class="prof-seg ch-act" id="profSchedToggle">
                    <button class="on" data-mode="today">Сегодня</button>
                    <button data-mode="week">Неделя</button>
                    <button data-mode="month">Месяц</button>
                </div>
            </div>
            <div id="profSchedBody"></div>
        </div>
    </div>`;

    renderSched('today');

    const toggle = root.querySelector('#profSchedToggle');
    toggle.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        toggle.querySelectorAll('button').forEach(x => x.classList.remove('on'));
        b.classList.add('on');
        state.weekOffset  = 0; // каждый вход в режим — текущая неделя / текущий месяц
        state.monthOffset = 0;
        renderSched(b.dataset.mode);
    }));

    root.querySelectorAll('details[data-acc]').forEach(el =>
        el.addEventListener('toggle', () => saveAccOpen(el.dataset.acc, el.open)));

    // НБ-11: расписание перерисовывается (смена режима, пагинация недель) —
    // делегируем клик на контейнере, чтобы переходы в журнал переживали ре-рендер.
    const schedBody = root.querySelector('#profSchedBody');
    if (schedBody) schedBody.addEventListener('click', e => {
        const el = e.target.closest('[data-grp]');
        if (el) nav.openJournalFor(el.dataset.grp);
    });

    root.querySelectorAll('.prof-grp-card[data-grp], .prof-work-item[data-grp]').forEach(el =>
        el.addEventListener('click', () => nav.openJournalFor(el.dataset.grp)));
    root.querySelectorAll('[data-review]').forEach(el =>
        el.addEventListener('click', () => nav.openReview()));
}

function renderSched(mode) {
    const body = document.getElementById('profSchedBody');
    if (!body) return;
    const d = state.data;

    if (mode === 'month') {
        renderMonth(body);
    } else if (mode === 'week') {
        const byDate = itemsByDate();

        // #18 + НБ-11: 7 дней недели (Пн–Вс), даже пустые. Опорный понедельник —
        // текущая неделя со сдвигом state.weekOffset (пагинация ‹ ›).
        const weekDates = datesFrom(state.weekOffset, 7);

        const grid = `<div class="prof-week-grid">${weekDates.map(date => {
            const items = byDate[date] || [];
            const cards = items.length
                ? items.map(it => `<div class="prof-week-card ${it.kind === 'individual' ? 'chip-bd-indi' : chipBorder(groupSubjectKey(it.group_id))}" data-grp="${it.group_id}">
                        <div class="wc-time">${esc(it.start)}</div>
                        <div class="wc-grp">${esc(it.kind === 'individual' && it.student_name ? it.student_name : it.group_name)}${it.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : ''}${it.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : ''}</div>
                        <div class="wc-topic">${esc(it.topic || '—')}</div>
                    </div>`).join('')
                : `<div class="prof-week-empty">Занятий нет</div>`;
            return `<div class="prof-week-col">
                    <div class="prof-week-dow">${DOW_JS[new Date(date + 'T00:00:00').getDay()]} ${date.slice(8, 10)}.${date.slice(5, 7)}</div>
                    ${cards}
                </div>`;
        }).join('')}</div>`;

        body.innerHTML = weekNav(weekDates[0], weekDates[6]) + grid;
        body.querySelectorAll('.pwn-arrow[data-wnav]').forEach(btn =>
            btn.addEventListener('click', () => { state.weekOffset += +btn.dataset.wnav; renderSched('week'); }));
    } else {
        body.innerHTML = d.today.length
            ? d.today.map(schedRow).join('')
            : `<div class="rev-empty">Сегодня занятий нет.</div>`;
    }
}

/**
 * «Месяц»: MONTH_WEEKS рядов по неделе (Пн–Вс), начиная с текущей недели;
 * ‹ › листают сразу на MONTH_WEEKS недель. В ячейке — все занятия дня (группа,
 * преподаватель, кабинет, тема), ряд растягивается по самому загруженному дню;
 * клик по дате открывает эту неделю в режиме «Неделя».
 */
function renderMonth(body) {
    const byDate = itemsByDate();
    const days   = datesFrom(state.monthOffset * MONTH_WEEKS, MONTH_WEEKS * 7);
    const today  = todayIso();

    const cells = days.map(date => {
        const items = (byDate[date] || []).map(monthItem).join('');

        return `<div class="prof-month-cell${date === today ? ' is-today' : ''}${date < today ? ' is-past' : ''}">
            <button type="button" class="pmc-day" data-day="${date}" aria-label="Открыть неделю ${esc(fmtDayMonth(date))}">${esc(dayLabel(date))}</button>
            ${items}
        </div>`;
    }).join('');

    body.innerHTML = schedNav('mnav', 'Предыдущий месяц', 'Следующий месяц', days[0], days[days.length - 1])
        + `<div class="prof-month-grid">
            ${DOW_RU.map(dow => `<div class="prof-month-dow">${dow}</div>`).join('')}
            ${cells}
        </div>`;

    body.querySelectorAll('.pwn-arrow[data-mnav]').forEach(btn =>
        btn.addEventListener('click', () => { state.monthOffset += +btn.dataset.mnav; renderSched('month'); }));
    body.querySelectorAll('[data-day]').forEach(btn => btn.addEventListener('click', () => openWeekOf(btn.dataset.day)));
}

/** Занятие в ячейке месяца: время и группа, преподаватель и кабинет, тема. */
function monthItem(it) {
    const who  = it.kind === 'individual' && it.student_name ? it.student_name : it.group_name;
    const meta = [it.teacher, it.room].filter(Boolean).map(esc).join(' · ');
    const tags = (it.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : '')
        + (it.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : '');

    return `<div class="prof-month-item ${it.kind === 'individual' ? 'chip-bd-indi' : chipBorder(groupSubjectKey(it.group_id))}" data-grp="${it.group_id}">
        <div class="pmi-head"><span class="pmi-time">${esc(it.start)}</span><span class="pmi-grp">${esc(who)}</span>${tags}</div>
        ${meta ? `<div class="pmi-meta">${meta}</div>` : ''}
        ${it.topic ? `<div class="pmi-topic" title="${esc(it.topic)}">${esc(it.topic)}</div>` : ''}
    </div>`;
}

/** Переключает расписание на «Неделю», в которой лежит дата. */
function openWeekOf(date) {
    const days = (new Date(date + 'T00:00:00') - mondayOf(0)) / 86400000;
    state.weekOffset = Math.floor(Math.round(days) / 7);

    const toggle = root.querySelector('#profSchedToggle');
    toggle.querySelectorAll('button').forEach(x => x.classList.toggle('on', 'week' === x.dataset.mode));
    renderSched('week');
}

/** Первое число месяца подписываем месяцем — иначе в сетке теряется граница. */
function dayLabel(date) {
    return '01' === date.slice(8, 10) ? fmtDayMonth(date) : String(+date.slice(8, 10));
}

/** Всё расписание, разложенное по датам. */
function itemsByDate() {
    const byDate = {};
    state.data.week.forEach(it => { (byDate[it.date] = byDate[it.date] || []).push(it); });
    return byDate;
}

/** Понедельник текущей недели со сдвигом на weekOffset недель. */
function mondayOf(weekOffset) {
    const monday = new Date();
    monday.setHours(0, 0, 0, 0);
    monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7) + weekOffset * 7);
    return monday;
}

/**
 * count дат подряд от понедельника недели со сдвигом weekOffset. Формат дат
 * локальный (без сдвига часового пояса, как у toISOString).
 */
function datesFrom(weekOffset, count) {
    const monday = mondayOf(weekOffset);
    const fmtIso = dt => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;

    return Array.from({ length: count }, (_, i) => {
        const dt = new Date(monday);
        dt.setDate(monday.getDate() + i);
        return fmtIso(dt);
    });
}

/** НБ-11: пагинатор недели (‹ диапазон ›) над сеткой расписания. */
function weekNav(fromIso, toIso) {
    return schedNav('wnav', 'Предыдущая неделя', 'Следующая неделя', fromIso, toIso);
}

function schedNav(attr, prevLabel, nextLabel, fromIso, toIso) {
    return `<div class="prof-week-nav">
        <button type="button" class="pwn-arrow" data-${attr}="-1" aria-label="${prevLabel}">‹</button>
        <div class="pwn-label">${esc(fmtDayMonth(fromIso))} – ${esc(fmtDayMonth(toIso))}</div>
        <button type="button" class="pwn-arrow" data-${attr}="1" aria-label="${nextLabel}">›</button>
    </div>`;
}

/**
 * Карточка-аккордеон главной. Изначально свёрнута, дальше — как оставил
 * преподаватель.
 */
function accordion(key, title, sub, bodyHtml) {
    const open = true === readAccOpen()[key];

    return `<details class="prof-card prof-acc" data-acc="${key}"${open ? ' open' : ''}>
        <summary class="prof-card-head prof-acc-head">
            <h3>${esc(title)}</h3>
            <span class="ch-sub">${esc(sub)}</span>
            <span class="prof-acc-chev">${icoChevronDown(14)}</span>
        </summary>
        <div class="prof-acc-body">${bodyHtml}</div>
    </details>`;
}

function readAccOpen() {
    try { return JSON.parse(localStorage.getItem(ACC_KEY) || '{}') || {}; } catch { return {}; }
}

function saveAccOpen(key, open) {
    try { localStorage.setItem(ACC_KEY, JSON.stringify(Object.assign(readAccOpen(), { [key]: open }))); } catch { /* браузер без хранилища — просто не запомним */ }
}

function statTile(label, val, delta, color, ico) {
    const icons = { cal: icoCalendar, check: icoCheck, alert: icoAlert };
    return `<div class="prof-stat-tile">
        <div class="st-top">
            <span class="st-ico" style="background:${color}1a;color:${color}">${icons[ico](16)}</span>
            ${esc(label)}
        </div>
        <div class="st-val">${esc(val)}</div>
        <div class="st-delta">${esc(delta)}</div>
    </div>`;
}

function schedRow(l) {
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

function grpCard(g) {
    const isFuture = g.covering_from && g.covering_from > todayIso();
    const badge = g.covering_until
        ? ( isFuture
            ? `<span class="prof-sub-tag">замещаете с ${fmtDayMonth(g.covering_from)}</span>`
            : `<span class="prof-sub-tag">замещаете до ${fmtDayMonth(g.covering_until)}</span>` )
        : ( g.covered_until ? `<span class="prof-sub-tag warn">замена до ${fmtDayMonth(g.covered_until)}</span>` : '' );
    return `<div class="prof-grp-card" data-grp="${g.id}">
        <span class="prof-group-chip ${chipBg(groupSubjectKey(g.id))}">${esc(shortName(g.name))}</span>
        <div class="prof-group-meta">
            <div class="prof-group-name">${esc(g.name)} · ${esc(g.subject)}</div>
            <div class="prof-group-sub">${g.students} ${plural(g.students, 'ученик', 'ученика', 'учеников')} ${badge}</div>
        </div>
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
