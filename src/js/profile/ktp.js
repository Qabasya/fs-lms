/* ══════════════════════════════════════════════════════════════════════
   КТП / Расписание — реальные данные через AJAX (Эпик 1).
   Источник: window.fsProfile.{groups, schedule:{nonce,actions}, ajax.url}.
   getCalendar → банк тем + календарь; drag → pin_lesson; «Распределить» → reflow.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openCtxMenu, openCtxMenuRaw, closeCtxMenu } from './utils.js';
import { createApi } from './api.js';

const MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const DOW_RU = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
const GROUP_COLORS = ['#3b5bdb','#0ca678','#7048e8','#f08c00','#e8590c','#1c7ed6','#e64980','#2f9e44'];

let root = null;
let state = null;
let api = null;
let coursesApi = null;

export function renderKTP(r) {
    root = r;
    const p = window.fsProfile || {};
    state = {
        groups:  Array.isArray(p.groups) ? p.groups : [],
        sched:   p.schedule || null,
        groupId: null,
        data:    null,
        months:  [],
        cursor:  0,
        dragGlid: null,
    };
    api = createApi(state.sched);
    coursesApi = p.courses ? createApi(p.courses) : null;

    if (!state.groups.length || !state.sched) {
        root.innerHTML = noGroupsHtml();
        return;
    }

    state.groupId = state.groups[0].id;
    loadCalendar();
}

function groupColor(id) {
    const idx = state.groups.findIndex(g => g.id === id);
    return GROUP_COLORS[(idx < 0 ? 0 : idx) % GROUP_COLORS.length];
}
function currentGroup() {
    return state.groups.find(g => g.id === state.groupId) || state.groups[0];
}
/* T1.8: КТП опубликована (заблокирована) — правки структуры/расписания недоступны. */
function isLocked() { return !!(state.data && state.data.locked); }

/* ── AJAX ─────────────────────────────────────────────────────────────── */
async function loadCalendar() {
    try {
        state.data = await api('getCalendar', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = errorHtml(e.message);
        return;
    }
    state.months = computeMonths(state.data.period);
    state.cursor = initialCursor();
    render();
}

function computeMonths(period) {
    const months = [];
    if (!period || !period.start_date || !period.end_date) return months;
    const [sy, sm] = period.start_date.split('-').map(Number);
    const [ey, em] = period.end_date.split('-').map(Number);
    let y = sy, m = sm - 1;
    while (y < ey || (y === ey && m <= em - 1)) {
        months.push({ y, m });
        m++; if (m > 11) { m = 0; y++; }
    }
    return months;
}

function initialCursor() {
    const placed = (state.data.themes || []).filter(t => t.scheduled_at).map(t => t.scheduled_at.slice(0, 7));
    if (!placed.length || !state.months.length) return 0;
    placed.sort();
    const [y, m] = placed[0].split('-').map(Number);
    const idx = state.months.findIndex(mm => mm.y === y && mm.m === m - 1);
    return idx < 0 ? 0 : idx;
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const g = currentGroup();
    const assigned = state.data.assigned;
    const locked = isLocked();

    root.innerHTML = `
    <div class="prof-ktp">
        <div class="prof-ktp-head">
            <div class="prof-ktp-pickers">
                <div class="prof-ktp-pick">
                    <span class="kp-label">Группа</span>
                    <button class="kp-btn" id="ktpGroupBtn">
                        <span class="kp-chip" style="background:${groupColor(g.id)}">${esc(shortName(g.name))}</span>
                        <span class="kp-txt">${esc(g.name)} · ${esc(g.subject)}</span>
                        <svg class="kp-caret" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg>
                    </button>
                </div>
            </div>
            <span style="flex:1"></span>
            ${assigned ? `
                <div class="prof-ktp-legend">
                    <span class="kl"><span class="prof-dot" style="background:var(--g-good)"></span>Тема по плану</span>
                    <span class="kl"><span class="prof-dot" style="background:var(--accent)"></span>Закреплено</span>
                    <span class="kl"><span class="prof-dot" style="background:var(--absent)"></span>Выходной</span>
                </div>
                ${locked ? `
                <span class="ktp-lock-badge" title="Опубликовано${state.data.locked_at ? ' ' + esc(state.data.locked_at) : ''}">
                    <svg width="13" height="13" viewBox="0 0 14 14" fill="none"><path d="M3.5 6V4.5a3.5 3.5 0 0 1 7 0V6M2.75 6h8.5v6h-8.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
                    Опубликовано
                </span>
                <button class="prof-btn prof-btn-sm" id="ktpUnpublish">Снять публикацию</button>` : `
                <button class="prof-btn prof-btn-sm prof-btn-primary" id="ktpReflow">
                    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 7h9m0 0-3-3m3 3-3 3M16 13H7m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Распределить
                </button>
                <button class="prof-btn prof-btn-sm" id="ktpPublish">Опубликовать</button>`}` : ''}
        </div>

        ${assigned ? `
        <div class="prof-ktp-grid">
            <div class="prof-theme-bank">
                <div class="tb-head"><h3>Темы курса</h3><span class="tbh-count" id="ktpBankCount"></span></div>
                <div class="prof-theme-list" id="ktpBank"></div>
            </div>
            <div class="prof-kal">
                <div class="kal-head">
                    <button class="prof-icon-ghost" id="ktpPrev"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M12 5l-5 5 5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    <div class="kal-month" id="ktpMonth"></div>
                    <button class="prof-icon-ghost" id="ktpNext"><svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    <span style="flex:1"></span>
                    <span id="ktpHint" style="font-size:12px;color:var(--muted)">${locked ? 'КТП опубликована — редактирование заблокировано' : 'Перетащите тему на дату, чтобы закрепить'}</span>
                </div>
                <div class="kal-grid-wrap">
                    <div class="kal-dow">${DOW_RU.map(d => `<span>${d}</span>`).join('')}</div>
                    <div class="kal-grid" id="ktpGrid"></div>
                </div>
            </div>
        </div>` : emptyStateHtml(g)}
    </div>`;

    document.getElementById('ktpGroupBtn').onclick = openGroupMenu;

    if (assigned) {
        if (locked) {
            document.getElementById('ktpUnpublish').onclick = doUnpublish;
        } else {
            document.getElementById('ktpReflow').onclick = doReflow;
            document.getElementById('ktpPublish').onclick = doPublish;
        }
        document.getElementById('ktpPrev').onclick = () => shiftMonth(-1);
        document.getElementById('ktpNext').onclick = () => shiftMonth(1);
        renderBank();
        renderCalendar();
    } else {
        wireCoursePicker();
    }
}

/* Курс-пикер в пустом состоянии (T11.1): список курсов предмета → назначить. */
async function wireCoursePicker() {
    const sel = document.getElementById('ktpCourseSel');
    const btn = document.getElementById('ktpAssignBtn');
    if (!sel || !btn || !coursesApi) { return; }

    try {
        const d = await coursesApi('getCourses', { group_id: state.groupId });
        const courses = (d && d.courses) || [];
        sel.innerHTML = courses.length
            ? '<option value="">— выберите курс —</option>' + courses.map(c => `<option value="${c.id}">${esc(c.title)}</option>`).join('')
            : '<option value="">Нет курсов по этому предмету</option>';
    } catch (e) {
        sel.innerHTML = '<option value="">Не удалось загрузить курсы</option>';
        toast(e.message);
    }

    sel.addEventListener('change', () => { btn.disabled = !sel.value; });
    btn.addEventListener('click', async () => {
        if (!sel.value) { return; }
        btn.disabled = true;
        try {
            await coursesApi('assignCourse', { group_id: state.groupId, course_id: sel.value });
            toast('Курс назначен');
            await loadCalendar();
        } catch (e) { toast(e.message); btn.disabled = false; }
    });
}

function renderBank() {
    const bank = document.getElementById('ktpBank');
    if (!bank) return;
    const unplaced = state.data.themes.filter(t => !t.scheduled_at);
    bank.innerHTML = unplaced.length
        ? unplaced.map(themeCardHtml).join('')
        : `<div style="padding:18px;color:var(--muted-2);font-size:13px">Все темы распределены по датам.</div>`;
    const count = document.getElementById('ktpBankCount');
    if (count) {
        const placed = state.data.themes.length - unplaced.length;
        count.textContent = `${placed} / ${state.data.themes.length} распределено`;
    }
    if (!isLocked()) bank.querySelectorAll('.prof-theme-card').forEach(attachDrag);
}

function renderCalendar() {
    if (!state.months.length) return;
    const { y, m } = state.months[state.cursor];
    document.getElementById('ktpMonth').textContent = `${MONTHS_RU[m]} ${y}`;
    document.getElementById('ktpPrev').disabled = state.cursor <= 0;
    document.getElementById('ktpNext').disabled = state.cursor >= state.months.length - 1;

    const holidays = new Set(state.data.holidays || []);
    const lessonDays = new Set(state.data.lessonDays || []);
    const lessonTimes = state.data.lessonTimes || {};
    // T12.5: на один день может быть две (и более) темы одной группы — стек, не перезапись.
    const byDate = {};
    state.data.themes.forEach(t => {
        if (!t.scheduled_at) return;
        const ds = t.scheduled_at.slice(0, 10);
        (byDate[ds] = byDate[ds] || []).push(t);
    });

    const first = new Date(y, m, 1);
    const offset = (first.getDay() + 6) % 7;
    const last = new Date(y, m + 1, 0).getDate();

    let cells = '';
    for (let i = 0; i < offset; i++) cells += `<div class="kal-cell empty"></div>`;
    for (let d = 1; d <= last; d++) {
        const ds = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const isHol = holidays.has(ds);
        const isLesson = lessonDays.has(ds);
        const dayThemes = byDate[ds] || [];

        let cls = 'kal-cell';
        if (isHol) cls += ' holiday';
        else if (!isLesson) cls += ' no-lesson';

        cells += `<div class="${cls}" data-day="${ds}" data-lesson="${isLesson && !isHol ? 1 : 0}">
            <div class="kal-date">
                <span class="kd-num">${d}</span>
                ${isHol ? `<span class="kd-tag hol">вых</span>` : ''}
                ${isLesson && !isHol ? `<span class="kd-lesson">${lessonTimes[ds] ? esc(lessonTimes[ds]) : 'урок'}</span>` : ''}
            </div>
            ${dayThemes.map(placedThemeHtml).join('')}
        </div>`;
    }

    const grid = document.getElementById('ktpGrid');
    grid.innerHTML = cells;
    // T12.3: дедлайны — delivery, не структура/расписание — доступны даже при lock КТП (T1.8).
    grid.querySelectorAll('.placed-theme').forEach(attachDeadlinesClick);
    if (!isLocked()) {
        grid.querySelectorAll('.kal-cell[data-lesson="1"]').forEach(attachDrop);
        grid.querySelectorAll('.placed-theme[draggable="true"]').forEach(attachDrag);
    }
}

function themeCardHtml(t) {
    return `<div class="prof-theme-card" draggable="true" data-glid="${t.group_lesson_id}">
        <span class="tc-num">${t.n}</span>
        <div class="tc-body">
            <div class="tc-title">${esc(t.topic || 'Без названия')}</div>
            <div class="tc-meta">${t.is_pinned ? '<span style="color:var(--accent);font-weight:600">закреплено</span>' : ''}</div>
        </div>
        <span class="tc-grip"><svg width="14" height="14" viewBox="0 0 14 14"><path fill="currentColor" d="M5 3h1v1H5zm3 0h1v1H8zM5 6.5h1v1H5zm3 0h1v1H8zM5 10h1v1H5zm3 0h1v1H8z"/></svg></span>
    </div>`;
}

function placedThemeHtml(t) {
    const pinned = t.is_pinned ? ' pinned' : '';
    const roomTip = t.room ? ` · ауд. ${t.room}` : '';
    return `<div class="placed-theme${pinned}" draggable="true" data-glid="${t.group_lesson_id}" title="${esc(t.topic)}${esc(roomTip)}">
        <span class="pt-pin"><svg width="11" height="11" viewBox="0 0 14 14" fill="currentColor"><path d="M9.5 1.5 12.5 4.5 10 7l.5 3-3-2-3.5 3.5L4.5 8 2 7.5 4.5 5 7 4z"/></svg></span>
        <span class="pt-num">№${t.n}</span>
        <span class="pt-title">${esc(t.topic || 'Без названия')}</span>
        ${t.room ? `<span class="pt-room">ауд. ${esc(t.room)}</span>` : ''}
    </div>`;
}

function shortName(name) {
    return String(name).replace(/[«»]/g, '').replace(/\s+/g, ' ').trim().slice(0, 4);
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function shiftMonth(d) {
    state.cursor = Math.max(0, Math.min(state.months.length - 1, state.cursor + d));
    renderCalendar();
}

function openGroupMenu() {
    openCtxMenu(
        document.getElementById('ktpGroupBtn'),
        state.groups.map(g => ({
            v: String(g.id),
            label: `${g.name} · ${g.subject}`,
            active: g.id === state.groupId,
            swatch: groupColor(g.id),
            chip: shortName(g.name),
        })),
        v => {
            const id = parseInt(v, 10);
            if (id !== state.groupId) { state.groupId = id; loadCalendar(); }
        }
    );
}

async function doReflow() {
    try {
        const res = await api('reflow', { group_id: state.groupId });
        const conflicts = res && res.room_conflicts ? +res.room_conflicts : 0;
        toast(conflicts > 0
            ? `Темы распределены · кабинет снят с ${conflicts} занятий (был занят)`
            : 'Темы распределены автоматически');
        await loadCalendar();
    } catch (e) {
        toast(e.message);
    }
}

/* T1.8: публикация/снятие публикации КТП. */
async function doPublish() {
    try {
        await api('publish', { group_id: state.groupId });
        toast('КТП опубликована — редактирование заблокировано');
        await loadCalendar();
    } catch (e) { toast(e.message); }
}

/* ── Дедлайны работ занятия (T12.3, D13) ─────────────────────────────────
   Клик по размещённой теме → поповер со списком эффективных работ занятия +
   datetime-local на каждую (по умолчанию пусто = дедлайна нет). Доступно
   даже при lock КТП — дедлайны это delivery, не структура/расписание. */
function attachDeadlinesClick(el) {
    el.addEventListener('click', () => openDeadlinesPopover(el.dataset.glid, el));
}

async function openDeadlinesPopover(glid, anchorEl) {
    let works;
    try {
        const res = await api('getDeadlines', { group_lesson_id: glid });
        works = res.works || [];
    } catch (e) { toast(e.message); return; }

    const html = `
        <div class="wd-pop">
            <div class="ctx-title">Дедлайны работ</div>
            ${works.length ? works.map(w => `
                <div class="wd-row" data-work-id="${w.id}">
                    <span class="wd-title" title="${esc(w.title)}">${esc(w.title)}</span>
                    <input type="datetime-local" class="wd-input" value="${w.deadline ? toLocalInputValue(w.deadline) : ''}">
                </div>`).join('') : '<div class="wd-empty">На этом занятии нет работ.</div>'}
            ${works.length ? '<button type="button" class="prof-btn prof-btn-sm prof-btn-primary wd-save">Сохранить</button>' : ''}
        </div>`;
    openCtxMenuRaw(html, anchorEl);
    const menu = document.getElementById('profCtxMenu');
    const saveBtn = menu?.querySelector('.wd-save');
    if (!saveBtn) return;
    saveBtn.addEventListener('click', async () => {
        const deadlines = {};
        menu.querySelectorAll('.wd-row').forEach(row => {
            const val = row.querySelector('.wd-input').value;
            deadlines[row.dataset.workId] = val ? fromLocalInputValue(val) : '';
        });
        saveBtn.disabled = true;
        try {
            await api('saveDeadlines', { group_lesson_id: glid, deadlines: JSON.stringify(deadlines) });
            toast('Дедлайны сохранены');
            closeCtxMenu();
        } catch (e) { toast(e.message); saveBtn.disabled = false; }
    });
}

/** '2026-08-01 12:00:00' → '2026-08-01T12:00' (значение <input type="datetime-local">). */
function toLocalInputValue(mysqlDateTime) {
    return mysqlDateTime.slice(0, 16).replace(' ', 'T');
}
/** '2026-08-01T12:00' → '2026-08-01 12:00:00'. */
function fromLocalInputValue(inputValue) {
    return inputValue.replace('T', ' ') + ':00';
}

function attachDrag(el) {
    el.addEventListener('dragstart', e => {
        state.dragGlid = el.dataset.glid;
        el.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', state.dragGlid);
    });
    el.addEventListener('dragend', () => {
        state.dragGlid = null;
        el.classList.remove('dragging');
        document.querySelectorAll('.drop-ok').forEach(n => n.classList.remove('drop-ok'));
    });
}

function attachDrop(cell) {
    cell.addEventListener('dragover', e => { e.preventDefault(); cell.classList.add('drop-ok'); });
    cell.addEventListener('dragleave', () => cell.classList.remove('drop-ok'));
    cell.addEventListener('drop', async e => {
        e.preventDefault();
        cell.classList.remove('drop-ok');
        if (!state.dragGlid) return;
        const glid = state.dragGlid;
        const day = cell.dataset.day;
        try {
            await api('pin', { group_lesson_id: glid, scheduled_at: `${day} 09:00:00` });
            toast(`Тема закреплена на ${day}`);
            await loadCalendar();
        } catch (err) {
            toast(err.message);
        }
    });
}

/* ── States ───────────────────────────────────────────────────────────── */
function emptyStateHtml(g) {
    return `<div class="prof-ktp-empty">
        <div class="ke-ico">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="3" y="4.5" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 9h18M8 2.5v4M16 2.5v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </div>
        <h3>Для группы ${esc(g.name)} не назначен курс</h3>
        <p>Выберите курс предмета — появятся темы и календарь для распределения.</p>
        <div class="ke-assign">
            <select id="ktpCourseSel" class="ke-course-sel"><option value="">— загрузка курсов… —</option></select>
            <button class="prof-btn prof-btn-primary" id="ktpAssignBtn" disabled>Назначить курс</button>
        </div>
    </div>`;
}

function noGroupsHtml() {
    return `<div class="prof-ktp"><div class="prof-ktp-empty">
        <div class="ke-ico"><svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="3" y="4.5" width="18" height="16" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 9h18" stroke="currentColor" stroke-width="1.6"/></svg></div>
        <h3>Нет групп</h3>
        <p>За вами пока не закреплены группы.</p>
    </div></div>`;
}

function errorHtml(msg) {
    return `<div class="prof-ktp"><div class="prof-ktp-empty">
        <div class="ke-ico" style="background:var(--absent-bg);color:var(--absent)"><svg width="30" height="30" viewBox="0 0 20 20" fill="none"><path d="M10 4v7M10 14.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
        <h3>Не удалось загрузить КТП</h3>
        <p>${esc(msg || '')}</p>
    </div></div>`;
}
