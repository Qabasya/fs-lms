/* ══════════════════════════════════════════════════════════════════════
   Главная кабинета — реальные данные через AJAX (Эпик 6).
   Источник: window.fsProfile.dashboard:{nonce,actions}. Кросс-групповой агрегат:
   расписание сегодня/неделя, ворклист «заполнить»/«проверить», стат-плитки,
   маркеры замен (Эпик 5). Демо-слой (data.js) убран.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast } from './utils.js';
import { createApi } from './api.js';

const DOW_JS = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const COLORS = ['#3b5bdb', '#0ca678', '#7048e8', '#f08c00', '#e8590c', '#1c7ed6', '#e64980', '#2f9e44'];

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

function colorFor(gid) { return COLORS[Math.abs(hash(String(gid))) % COLORS.length]; }
function hash(s) { let h = 0; for (let i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; } return h; }

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
                <div class="prof-seg" id="profSchedToggle" style="margin-left:auto">
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
        const dates = Object.keys(byDate).sort();
        body.innerHTML = dates.length
            ? `<div class="prof-week-grid">${dates.map(date => `
                <div class="prof-week-col">
                    <div class="prof-week-dow">${DOW_JS[new Date(date).getDay()]} ${date.slice(8, 10)}.${date.slice(5, 7)}</div>
                    ${byDate[date].map(it => `<div class="prof-week-card" style="border-left-color:${it.kind === 'individual' ? 'var(--t-zachet)' : colorFor(it.group_id)}" data-grp="${it.group_id}">
                        <div class="wc-time">${esc(it.start)}</div>
                        <div class="wc-grp">${esc(it.group_name)}${it.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : ''}</div>
                        <div class="wc-topic">${esc(it.topic || '—')}</div>
                    </div>`).join('')}
                </div>`).join('')}</div>`
            : `<div class="rev-empty">На этой неделе занятий нет.</div>`;
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
        <div class="st-delta prof-st-delta">${esc(delta)}</div>
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
        <div class="prof-lesson-bar" style="background:${l.kind === 'individual' ? 'var(--t-zachet)' : colorFor(l.group_id)}"></div>
        <div class="prof-lesson-body">
            <div class="prof-lesson-grp">${esc(l.group_name)}${l.is_substitute ? ' <span class="prof-sub-tag">замена</span>' : ''}${l.kind === 'individual' ? ' <span class="prof-sub-tag indi">инд.</span>' : ''}</div>
            <div class="prof-lesson-topic">${esc(l.topic || '—')}</div>
            <div class="prof-lesson-meta"><span class="lm">${esc(l.subject)}</span>${l.room ? `<span class="lm"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2C5.5 2 4 3.8 4 6c0 3 4 8 4 8s4-5 4-8c0-2.2-1.5-4-4-4z" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="6" r="1.4" fill="currentColor"/></svg>${esc(l.room)}</span>` : ''}</div>
        </div>
        <div class="prof-lesson-state">${stateMap[l.state] || ''}</div>
    </div>`;
}

function fillRow(w) {
    return `<div class="prof-work-item" data-grp="${w.group_id}" style="cursor:pointer">
        <div class="prof-work-ico att"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M4 6h12v10H4zM4 9h12M7 4v3M13 4v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></div>
        <div class="prof-work-main">
            <div class="prof-work-title">Заполнить посещаемость · ${esc(w.group_name)}</div>
            <div class="prof-work-sub">${esc(w.topic || '—')} · ${fmtDate(w.date)}</div>
        </div>
        <span class="prof-work-count">${w.missing}</span>
        <svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M7 5l5 5-5 5" stroke="var(--muted-2)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>`;
}

function reviewRow(w) {
    return `<div class="prof-work-item" data-review="1" style="cursor:pointer">
        <div class="prof-work-ico rev" style="background:#7048e81a;color:#7048e8">
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
        ? `<span class="prof-sub-tag">замещаете до ${fmtDate(g.covering_until)}</span>`
        : ( g.covered_until ? `<span class="prof-sub-tag warn">замена до ${fmtDate(g.covered_until)}</span>` : '' );
    return `<div class="prof-grp-card" data-grp="${g.id}">
        <span class="prof-group-chip" style="background:${colorFor(g.id)}">${esc(String(g.name).replace(/[«»\s]/g, '').slice(0, 4))}</span>
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
        <span>Вы замещаете: ${covering.map(c => `${esc(c.group_name)} <b>до ${fmtDate(c.valid_to)}</b>`).join(', ')}</span>
    </div>`;
}

/* ── Helpers ──────────────────────────────────────────────────────────── */
function fmtDate(s) {
    if (!s) return '';
    const p = String(s).slice(0, 10).split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}` : s;
}

function plural(n, one, few, many) {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
}

function emptyHtml(title, text) {
    return `<div class="prof-dash"><div class="prof-ktp-empty">
        <div class="ke-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none"><path d="M3 9.5 12 3l9 6.5M6 8.5V20h12V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
        <h3>${esc(title)}</h3><p>${esc(text || '')}</p>
    </div></div>`;
}
