/* ══════════════════════════════════════════════════════════════════════
   Главная кабинета — реальные данные через AJAX (Эпик 6).
   Источник: window.fsProfile.dashboard:{nonce,actions}. Кросс-групповой агрегат:
   расписание сегодня/неделя, ворклист «заполнить»/«проверить», стат-плитки,
   маркеры замен (Эпик 5). Демо-слой (data.js) убран.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, plural, fmtDayMonth, groupColor, shortName, emptyState } from './utils.js';
import { createApi } from './api.js';
import { DOW_JS } from './constants.js';

let root = null;
let state = null;
let api = null;
let nav = { openJournalFor: () => {}, openReview: () => {} };

export function renderDashboard(r, handlers) {
    root = r;
    nav = Object.assign(nav, handlers || {});
    const p = window.fsProfile || {};
    state = { cfg: p.dashboard || null, data: null };
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
            ${statTile('Занятий сегодня', String(s.lessons_today), `${s.groups} ${plural(s.groups, 'группа', 'группы', 'групп')}`, '#3b5bdb', 'cal')}
            ${statTile('На проверке', String(s.to_review), 'работ ждут оценки', '#7048e8', 'check')}
            ${statTile('Не заполнено', String(s.to_fill), 'журналов посещаемости', '#e03131', 'alert')}
        </div>

        <div class="prof-card prof-sched-card">
            <div class="prof-card-head">
                <h3>Расписание</h3>
                <div class="prof-seg ch-act" id="profSchedToggle">
                    <button class="on" data-mode="today">Сегодня</button>
                    <button data-mode="week">Неделя</button>
                </div>
            </div>
            <div id="profSchedBody"></div>
        </div>

        <div class="prof-dash-grid2">
            <div class="prof-card">
                <div class="prof-card-head">
                    <h3>Требует внимания</h3>
                    <span class="ch-sub">${attnCount} ${plural(attnCount, 'задача', 'задачи', 'задач')}</span>
                </div>
                <div>
                    ${d.worklist.to_fill.map(fillRow).join('')}
                    ${d.worklist.to_review.map(reviewRow).join('')}
                    ${attnCount ? '' : '<div class="rev-empty">Всё в порядке — журналы заполнены, работы проверены.</div>'}
                </div>
            </div>
            <div class="prof-card">
                <div class="prof-card-head"><h3>Мои группы</h3></div>
                <div>
                    ${d.groups.length ? d.groups.map(grpCard).join('') : '<div class="rev-empty">Нет групп.</div>'}
                </div>
            </div>
        </div>
    </div>`;

    renderSched('today');

    const toggle = root.querySelector('#profSchedToggle');
    toggle.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        toggle.querySelectorAll('button').forEach(x => x.classList.remove('on'));
        b.classList.add('on');
        renderSched(b.dataset.mode);
    }));

    root.querySelectorAll('[data-grp]').forEach(el =>
        el.addEventListener('click', () => nav.openJournalFor(el.dataset.grp)));
    root.querySelectorAll('[data-review]').forEach(el =>
        el.addEventListener('click', () => nav.openReview()));
}

function renderSched(mode) {
    const body = document.getElementById('profSchedBody');
    if (!body) return;
    const d = state.data;

    if (mode === 'week') {
        const byDate = {};
        d.week.forEach(it => { (byDate[it.date] = byDate[it.date] || []).push(it); });

        // #18: выводим ВСЕ 7 дней недели (Пн–Вс), даже пустые. Понедельник берём
        // от первого занятия (все в одной неделе) либо от сегодня. Формат дат —
        // локальный (без сдвига часового пояса, как у toISOString).
        const fmtIso = dt => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
        const anchorStr = Object.keys(byDate).sort()[0];
        const monday = anchorStr ? new Date(anchorStr + 'T00:00:00') : new Date();
        monday.setHours(0, 0, 0, 0);
        monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7)); // к понедельнику

        const weekDates = [];
        for (let i = 0; i < 7; i++) {
            const dt = new Date(monday);
            dt.setDate(monday.getDate() + i);
            weekDates.push(fmtIso(dt));
        }

        body.innerHTML = `<div class="prof-week-grid">${weekDates.map(date => {
            const items = byDate[date] || [];
            const cards = items.length
                ? items.map(it => `<div class="prof-week-card" style="border-left-color:${it.kind === 'individual' ? 'var(--t-zachet)' : groupColor(it.group_id)}" data-grp="${it.group_id}">
                        <div class="wc-time">${esc(it.start)}</div>
                        <div class="wc-grp">${esc(it.group_name)}${it.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : ''}</div>
                        <div class="wc-topic">${esc(it.topic || '—')}</div>
                    </div>`).join('')
                : `<div class="prof-week-empty">Занятий нет</div>`;
            return `<div class="prof-week-col">
                    <div class="prof-week-dow">${DOW_JS[new Date(date + 'T00:00:00').getDay()]} ${date.slice(8, 10)}.${date.slice(5, 7)}</div>
                    ${cards}
                </div>`;
        }).join('')}</div>`;
    } else {
        body.innerHTML = d.today.length
            ? d.today.map(schedRow).join('')
            : `<div class="rev-empty">Сегодня занятий нет.</div>`;
    }
}

function statTile(label, val, delta, color, ico) {
    const icons = {
        cal:   '<path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        check: '<path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
        alert: '<path d="M10 4v7M10 14.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
    };
    return `<div class="prof-stat-tile">
        <div class="st-top">
            <span class="st-ico" style="background:${color}1a;color:${color}"><svg width="16" height="16" viewBox="0 0 20 20" fill="none">${icons[ico]}</svg></span>
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
    return `<div class="prof-lesson-row ${l.state === 'now' ? 'is-now' : ''}" data-grp="${l.group_id}">
        <div class="prof-lesson-time">
            <div class="lt-start">${esc(l.start)}</div>
            <div class="lt-end">${esc(l.end || '')}</div>
        </div>
        <div class="prof-lesson-bar" style="background:${l.kind === 'individual' ? 'var(--t-zachet)' : groupColor(l.group_id)}"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(l.group_name)}${l.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : ''}${l.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : ''}</div>
            <div class="prof-lesson-topic">${esc(l.topic || '—')}</div>
            <div class="prof-lesson-meta"><span class="lm">${esc(l.subject)}</span>${l.room ? `<span class="lm"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2C5.5 2 4 3.8 4 6c0 3 4 8 4 8s4-5 4-8c0-2.2-1.5-4-4-4z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="6" r="1.4" fill="currentColor"/></svg>${esc(l.room)}</span>` : ''}</div>
        </div>
        <div class="prof-lesson-state">${stateMap[l.state] || ''}</div>
    </div>`;
}

function fillRow(w) {
    return `<div class="prof-work-item is-clickable" data-grp="${w.group_id}">
        <div class="prof-work-ico att"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
        <div class="prof-work-main">
            <div class="prof-work-title">Заполнить посещаемость · ${esc(w.group_name)}</div>
            <div class="prof-work-sub">${esc(w.topic || '—')} · ${fmtDayMonth(w.date)}</div>
        </div>
        <span class="prof-work-count">${w.missing}</span>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}

function reviewRow(w) {
    return `<div class="prof-work-item is-clickable" data-review="1">
        <div class="prof-work-ico grade">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M5 4h10v12l-5-2.5L5 16z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </div>
        <div class="prof-work-main">
            <div class="prof-work-title">Проверить работы · ${esc(w.group_name)}</div>
            <div class="prof-work-sub">в очереди на проверку</div>
        </div>
        <span class="prof-work-count">${w.count}</span>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}

function grpCard(g) {
    const badge = g.covering_until
        ? `<span class="prof-sub-tag">замещаете до ${fmtDayMonth(g.covering_until)}</span>`
        : ( g.covered_until ? `<span class="prof-sub-tag warn">замена до ${fmtDayMonth(g.covered_until)}</span>` : '' );
    return `<div class="prof-grp-card" data-grp="${g.id}">
        <span class="prof-group-chip" style="background:${groupColor(g.id)}">${esc(shortName(g.name))}</span>
        <div class="prof-group-meta">
            <div class="prof-group-name">${esc(g.name)} · ${esc(g.subject)}</div>
            <div class="prof-group-sub">${g.students} ${plural(g.students, 'ученик', 'ученика', 'учеников')} ${badge}</div>
        </div>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}

function coveringBanner(covering) {
    return `<div class="prof-cover-banner">
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M10 2l7 3v5c0 4-3 7-7 8-4-1-7-4-7-8V5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        <span>Вы замещаете: ${covering.map(c => `${esc(c.group_name)} <b>до ${fmtDayMonth(c.valid_to)}</b>`).join(', ')}</span>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
const EMPTY_ICON = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none"><path d="M3 9.5 12 3l9 6.5M6 8.5V20h12V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function emptyHtml(title, text) {
    return emptyState('prof-dash', EMPTY_ICON, title, text);
}
