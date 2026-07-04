/* ══════════════════════════════════════════════════════════════════════
   КТП / Расписание — реальные данные через AJAX (Эпик 1).
   Источник: window.fsProfile.{groups, schedule:{nonce,actions}, ajax.url}.
   getCalendar → банк тем + календарь; drag → pin_lesson; «Распределить» → reflow.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState, openCtxMenuRaw, closeCtxMenu } from './utils.js';
import { icoLock, icoSwap, icoChevronLeft, icoChevronRight, icoGrip, icoPinFilled, icoCaret, icoContinue, icoCalendarBoard, icoAlert } from '../common/icons.js';
import { createApi } from './api.js';
import { DOW_RU, MONTHS_RU } from './constants.js';
import { groupPickerBtnHtml, openGroupPicker } from './picker.js';
import { openIndiModal } from './indi-modal.js';

let root = null;
let state = null;
let api = null;
let coursesApi = null;

/* НБ-9: sentinel-id псевдо-«группы» = режим «Индивидуальные занятия». */
const INDI_ID = -1;

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
        individual: [],      // #1: инд. занятия всех групп (сквозной календарь)
        indiMonths: [],      // месяцы диапазона инд. занятий
        indiCursor: 0,       // текущий месяц календаря инд. занятий
        indiSelected: null,  // выбранный слот (group_lesson_id) для назначения урока
        indiCandidates: null, // кандидаты-уроки для выбранного слота (null = не загружены)
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
                    ${groupPickerBtnHtml(g, 'ktpGroupBtn')}
                </div>
            </div>
            <span class="prof-spacer"></span>
            ${assigned ? `
                <div class="prof-ktp-legend">
                    <span class="kl"><span class="prof-dot prof-dot-good"></span>Тема по плану</span>
                    <span class="kl"><span class="prof-dot prof-dot-accent"></span>Закреплено</span>
                    <span class="kl"><span class="prof-dot prof-dot-absent"></span>Выходной</span>
                </div>
                ${locked ? `
                <span class="ktp-lock-badge" title="Опубликовано${state.data.locked_at ? ' ' + esc(state.data.locked_at) : ''}">
                    ${icoLock(13)}
                    Опубликовано
                </span>
                <button class="prof-btn prof-btn-sm" id="ktpUnpublish">Снять публикацию</button>` : `
                <button class="prof-btn prof-btn-sm prof-btn-primary" id="ktpReflow">
                    ${icoSwap(15)}
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
                    <button class="prof-icon-ghost" id="ktpPrev">${icoChevronLeft(18)}</button>
                    <div class="kal-month" id="ktpMonth"></div>
                    <button class="prof-icon-ghost" id="ktpNext">${icoChevronRight(18)}</button>
                    <span class="prof-spacer"></span>
                    <span class="kal-hint" id="ktpHint">${locked ? 'КТП опубликована — редактирование заблокировано' : 'Перетащите тему на дату, чтобы закрепить'}</span>
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
        : `<div class="tb-empty">Все темы распределены по датам.</div>`;
    const count = document.getElementById('ktpBankCount');
    if (count) {
        // T12.6: считаем по уникальным темам (n), не по строкам — origin+continuation = 1 тема,
        // «распределена», только когда размещены ВСЕ её части.
        const byN = {};
        state.data.themes.forEach(t => (byN[t.n] = byN[t.n] || []).push(t));
        const groups = Object.values(byN);
        const placed = groups.filter(g => g.every(t => t.scheduled_at)).length;
        count.textContent = `${placed} / ${groups.length} распределено`;
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
        // T12.6: «Продолжить на другую дату» — структурное изменение, блокируется lock КТП.
        grid.querySelectorAll('.pt-more').forEach(attachThemeActionsClick);
    }
}

/* T12.6 (D14): «1/2 · 2/2» — origin+continuation считаются одной темой. */
function partLabel(t) {
    return (t.total_parts && t.total_parts > 1) ? ` · ${t.part}/${t.total_parts}` : '';
}

function themeCardHtml(t) {
    return `<div class="prof-theme-card" draggable="true" data-glid="${t.group_lesson_id}">
        <span class="tc-num">${t.n}${partLabel(t)}</span>
        <div class="tc-body">
            <div class="tc-title">${esc(t.topic || 'Без названия')}</div>
            <div class="tc-meta">${t.is_pinned ? '<span class="tc-pinned">закреплено</span>' : ''}</div>
        </div>
        <span class="tc-grip">${icoGrip(14)}</span>
    </div>`;
}

function placedThemeHtml(t) {
    const pinned = t.is_pinned ? ' pinned' : '';
    const roomTip = t.room ? ` · ауд. ${t.room}` : '';
    // T12.6: «Продолжить» доступно только для «родных» строк (part 1) — не для уже-продолжений.
    const canContinue = 1 === t.part;
    return `<div class="placed-theme${pinned}" draggable="true" data-glid="${t.group_lesson_id}" title="${esc(t.topic)}${esc(roomTip)}">
        <span class="pt-pin">${icoPinFilled(11)}</span>
        <span class="pt-num">№${t.n}${partLabel(t)}</span>
        <span class="pt-title">${esc(t.topic || 'Без названия')}</span>
        ${t.room ? `<span class="pt-room">ауд. ${esc(t.room)}</span>` : ''}
        ${canContinue ? `<button type="button" class="pt-more" data-glid="${t.group_lesson_id}" aria-label="Действия">⋮</button>` : ''}
    </div>`;
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function shiftMonth(d) {
    state.cursor = Math.max(0, Math.min(state.months.length - 1, state.cursor + d));
    renderCalendar();
}

function openGroupMenu() {
    openGroupPicker(document.getElementById('ktpGroupBtn'), state.groups, state.groupId, id => {
        state.groupId = id;
        if (INDI_ID === id) { loadIndividual(); } else { loadCalendar(); }
    }, [{ v: String(INDI_ID), label: 'Индивидуальные занятия', swatchClass: 'chip-indi', chip: 'Инд' }]);
}

/* ── Режим «Индивидуальные занятия» (#1) ──────────────────────────────────
   Дизайн повторяет групповую КТП: слева сайдбар уроков (поиск + «Уроки курса»
   / разделитель / «Все уроки предмета»), справа СКВОЗНОЙ календарь всех инд.
   занятий всех групп препода (D-1). Поток: клик по занятию в календаре →
   сайдбар грузит кандидатов этого слота → клик по уроку назначает его. */
async function fetchIndividual() {
    const perGroup = await Promise.all(state.groups.map(g =>
        api('getIndividual', { group_id: g.id }).then(d => (d && d.items) || []).catch(() => [])));
    state.individual = perGroup.flat().sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
    state.indiMonths = indiMonths(state.individual);
}

async function loadIndividual() {
    state.groupId = INDI_ID;
    state.indiSelected = null;
    state.indiCandidates = null;
    root.innerHTML = `<div class="prof-ktp"><div class="rev-loading">Загрузка…</div></div>`;
    try {
        await fetchIndividual();
    } catch (e) {
        root.innerHTML = errorHtml(e.message);
        return;
    }
    state.indiCursor = indiInitialCursor();
    renderIndividual();
}

/* Месяцы диапазона всех инд. занятий (min..max scheduled_at). */
function indiMonths(items) {
    const ym = items.map(it => String(it.scheduled_at || '').slice(0, 7)).filter(Boolean).sort();
    if (!ym.length) return [];
    const [sy, sm] = ym[0].split('-').map(Number);
    const [ey, em] = ym[ym.length - 1].split('-').map(Number);
    const months = [];
    let y = sy, m = sm - 1;
    while (y < ey || (y === ey && m <= em - 1)) {
        months.push({ y, m });
        m++; if (m > 11) { m = 0; y++; }
    }
    return months;
}

/* Открываем календарь на текущем месяце, если он в диапазоне, иначе на первом. */
function indiInitialCursor() {
    if (!state.indiMonths.length) return 0;
    const now = new Date();
    const idx = state.indiMonths.findIndex(mm => mm.y === now.getFullYear() && mm.m === now.getMonth());
    return idx >= 0 ? idx : 0;
}

function renderIndividual() {
    const items = state.individual || [];
    root.innerHTML = `
    <div class="prof-ktp prof-ktp-indi">
        <div class="prof-ktp-head">
            <div class="prof-ktp-pickers">
                <div class="prof-ktp-pick">
                    <span class="kp-label">Группа</span>
                    <button type="button" class="kp-btn" id="ktpGroupBtn">
                        <span class="kp-chip chip-indi">Инд</span>
                        <span class="kp-txt">Индивидуальные занятия</span>
                        ${icoCaret(12, 'kp-caret')}
                    </button>
                </div>
            </div>
            <span class="prof-spacer"></span>
            <div class="prof-ktp-legend">
                <span class="kl"><span class="prof-dot prof-dot-accent"></span>Урок назначен</span>
                <span class="kl"><span class="prof-dot prof-dot-absent"></span>Не назначен</span>
            </div>
        </div>
        ${items.length ? `
        <div class="prof-ktp-grid">
            <div class="prof-theme-bank">
                <div class="tb-head"><h3>Уроки</h3><span class="tbh-count" id="indiBankHint"></span></div>
                <input type="text" class="indi-search" id="indiSearch" placeholder="Поиск урока по названию…" ${state.indiSelected ? '' : 'disabled'}>
                <div class="prof-theme-list" id="indiBank"></div>
            </div>
            <div class="prof-kal">
                <div class="kal-head">
                    <button class="prof-icon-ghost" id="ktpPrev">${icoChevronLeft(18)}</button>
                    <div class="kal-month" id="ktpMonth"></div>
                    <button class="prof-icon-ghost" id="ktpNext">${icoChevronRight(18)}</button>
                    <span class="prof-spacer"></span>
                    <span class="kal-hint">Клик по дню — добавить · ✎ на занятии — изменить</span>
                </div>
                <div class="kal-grid-wrap">
                    <div class="kal-dow">${DOW_RU.map(d => `<span>${d}</span>`).join('')}</div>
                    <div class="kal-grid" id="ktpGrid"></div>
                </div>
            </div>
        </div>` : `<div class="prof-indi-empty"><p>Индивидуальных занятий пока нет.</p><button class="prof-btn prof-btn-primary" id="indiAddFirst">+ Добавить занятие</button></div>`}
    </div>`;

    document.getElementById('ktpGroupBtn').onclick = openGroupMenu;
    if (!items.length) {
        const add = document.getElementById('indiAddFirst');
        if (add) { add.addEventListener('click', () => openIndiModal({ api, anchor: add, groups: state.groups, onSaved: loadIndividual })); }
        return;
    }

    document.getElementById('ktpPrev').onclick = () => shiftIndiMonth(-1);
    document.getElementById('ktpNext').onclick = () => shiftIndiMonth(1);
    const search = document.getElementById('indiSearch');
    let deb;
    search.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(loadIndiCandidates, 250); });

    renderIndiCalendar();
    renderIndiBank();
}

function shiftIndiMonth(d) {
    state.indiCursor = Math.max(0, Math.min(state.indiMonths.length - 1, state.indiCursor + d));
    renderIndiCalendar();
}

function renderIndiCalendar() {
    if (!state.indiMonths.length) return;
    const { y, m } = state.indiMonths[state.indiCursor];
    document.getElementById('ktpMonth').textContent = `${MONTHS_RU[m]} ${y}`;
    document.getElementById('ktpPrev').disabled = state.indiCursor <= 0;
    document.getElementById('ktpNext').disabled = state.indiCursor >= state.indiMonths.length - 1;

    const byDate = {};
    (state.individual || []).forEach(it => {
        const ds = String(it.scheduled_at || '').slice(0, 10);
        if (ds) (byDate[ds] = byDate[ds] || []).push(it);
    });

    const first = new Date(y, m, 1);
    const offset = (first.getDay() + 6) % 7;
    const last = new Date(y, m + 1, 0).getDate();

    let cells = '';
    for (let i = 0; i < offset; i++) cells += `<div class="kal-cell empty"></div>`;
    for (let d = 1; d <= last; d++) {
        const ds = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const slots = (byDate[ds] || []).sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
        cells += `<div class="kal-cell${slots.length ? '' : ' no-lesson'}" data-day="${ds}">
            <div class="kal-date"><span class="kd-num">${d}</span></div>
            ${slots.map(indiSlotChip).join('')}
        </div>`;
    }

    const grid = document.getElementById('ktpGrid');
    grid.innerHTML = cells;
    // Клик по слоту — выбрать для назначения урока (сайдбар, НБ-9); ✎ — правка (B2).
    grid.querySelectorAll('.indi-slot').forEach(el => el.addEventListener('click', (e) => {
        if (e.target.closest('.indi-edit')) { return; }
        e.stopPropagation();
        selectIndiSlot(el.dataset.glid);
    }));
    grid.querySelectorAll('.indi-edit').forEach(btn => btn.addEventListener('click', (e) => {
        e.stopPropagation();
        openEditIndi(btn.dataset.glid, btn);
    }));
    // Клик по свободному месту дня — создать инд. занятие на эту дату (B2).
    grid.querySelectorAll('.kal-cell[data-day]').forEach(cell => cell.addEventListener('click', (e) => {
        if (e.target.closest('.indi-slot')) { return; }
        openAddIndi(cell.dataset.day, cell);
    }));
}

function indiSlotChip(it) {
    const t = String(it.scheduled_at || '').split(' ')[1];
    const time = t ? t.slice(0, 5) : '';
    const has = !!it.lesson_id;
    const sel = String(it.group_lesson_id) === String(state.indiSelected) ? ' selected' : '';
    const sub = has ? esc(it.topic || 'Без названия') : 'Урок не назначен';
    return `<div class="placed-theme indi-slot${has ? '' : ' unassigned'}${sel}" data-glid="${it.group_lesson_id}" title="${esc(it.student_name || '')} · ${esc(sub)}">
        <button class="indi-edit" data-glid="${it.group_lesson_id}" title="Изменить занятие" aria-label="Изменить">✎</button>
        <span class="pt-num">${esc(time)} · ${esc(it.student_name || '—')}</span>
        <span class="pt-title">${sub}</span>
    </div>`;
}

// B2: создать инд. занятие на выбранную дату календаря (группа/ученик — в модалке).
function openAddIndi(ds, anchor) {
    openIndiModal({
        api,
        anchor,
        groups: state.groups,
        fixed: { date: ds },
        onSaved: loadIndividual,
    });
}

// B2: правка инд. занятия (группа фиксирована, ученик/дата/время/кабинет/тема — меняются).
function openEditIndi(glid, anchor) {
    const slot = (state.individual || []).find(x => String(x.group_lesson_id) === String(glid));
    if (!slot) { return; }
    const parts = String(slot.scheduled_at || '').split(' ');
    openIndiModal({
        api,
        anchor,
        groups: state.groups,
        edit: {
            glid: slot.group_lesson_id,
            group_id: slot.group_id,
            student_person_id: slot.student_person_id,
            student_name: slot.student_name,
            date: parts[0] || '',
            time: parts[1] ? parts[1].slice(0, 5) : '15:00',
            time_end: (String(slot.ends_at || '').split(' ')[1] || '').slice(0, 5),
            room_id: slot.room_id || '',
            room_name: slot.room || '',
            lesson_id: slot.lesson_id || '',
        },
        onSaved: loadIndividual,
    });
}

function selectIndiSlot(glid) {
    state.indiSelected = glid;
    state.indiCandidates = null;
    const search = document.getElementById('indiSearch');
    if (search) { search.disabled = false; search.value = ''; }
    renderIndiCalendar(); // подсветить выбранный слот
    renderIndiBank();     // показать «Загрузка…»
    loadIndiCandidates();
}

async function loadIndiCandidates() {
    const slot = (state.individual || []).find(x => String(x.group_lesson_id) === String(state.indiSelected));
    const bank = document.getElementById('indiBank');
    if (!slot || !bank) return;
    const q = (document.getElementById('indiSearch')?.value || '').trim();
    bank.innerHTML = '<div class="pil-empty">Загрузка…</div>';
    try {
        const d = await api('lessonCandidates', { group_id: slot.group_id, search: q });
        state.indiCandidates = (d && d.lessons) || [];
        renderIndiBank();
    } catch (e) { bank.innerHTML = `<div class="pil-empty">${esc(e.message)}</div>`; }
}

function renderIndiBank() {
    const bank = document.getElementById('indiBank');
    if (!bank) return;
    const hint = document.getElementById('indiBankHint');
    const slot = (state.individual || []).find(x => String(x.group_lesson_id) === String(state.indiSelected));

    if (!slot) {
        bank.innerHTML = '<div class="pil-empty">Выберите занятие в календаре, чтобы назначить урок.</div>';
        if (hint) hint.textContent = '';
        return;
    }
    if (hint) hint.textContent = slot.student_name || '';
    if (state.indiCandidates === null) { bank.innerHTML = '<div class="pil-empty">Загрузка…</div>'; return; }

    bank.innerHTML = renderLessonCandidates(state.indiCandidates, slot.lesson_id);
    bank.querySelectorAll('.pil-item').forEach(el => el.addEventListener('click', () => assignIndiLesson(el.dataset.lid)));
}

async function assignIndiLesson(lid) {
    const glid = state.indiSelected;
    if (!glid) return;
    try {
        await api('assignLesson', { group_lesson_id: glid, lesson_id: lid });
        toast('Урок назначен');
        await fetchIndividual();   // обновить темы/lesson_id слотов
        renderIndiCalendar();      // перерисовать календарь с новым уроком
        renderIndiBank();          // обновить пометку «текущий»
    } catch (e) { toast(e.message); }
}

function renderLessonCandidates(lessons, currentId) {
    if (!lessons.length) return '<div class="pil-empty">Уроки не найдены.</div>';
    const section = (title, list) => list.length
        ? `<div class="pil-divider">${esc(title)}</div>` + list.map(l =>
            `<div class="pil-item${String(l.id) === String(currentId) ? ' current' : ''}" data-lid="${l.id}">${esc(l.title || 'Без названия')}</div>`).join('')
        : '';
    return section('Уроки курса', lessons.filter(l => l.in_course)) + section('Все уроки предмета', lessons.filter(l => !l.in_course));
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

async function doUnpublish() {
    try {
        await api('unpublish', { group_id: state.groupId });
        toast('Публикация снята — редактирование доступно');
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

/* ── Продолжение темы на вторую дату (T12.6, D14) ─────────────────────────
   «⋮» на размещённой теме → «Продолжить на другую дату» → в банке появляется
   связанная непристроенная копия — перетащите её на целевую дату тем же
   drag-flow, что и обычную тему. */
function attachThemeActionsClick(btn) {
    btn.addEventListener('click', e => {
        e.stopPropagation(); // не открывать поповер дедлайнов родительской темы
        openThemeActionsMenu(btn.dataset.glid, btn);
    });
}

function openThemeActionsMenu(glid, anchorEl) {
    const html = `
        <div class="ctx-item" data-act="continue">
            ${icoContinue(16)}
            Продолжить на другую дату
        </div>`;
    openCtxMenuRaw(html, anchorEl);
    const menu = document.getElementById('profCtxMenu');
    const item = menu?.querySelector('[data-act="continue"]');
    if (!item) return;
    item.addEventListener('click', async () => {
        closeCtxMenu();
        try {
            await api('continue', { group_lesson_id: glid });
            toast('Тема продолжена — перетащите копию из банка тем на вторую дату');
            await loadCalendar();
        } catch (e) { toast(e.message); }
    });
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
        // Время слота — из расписания группы (lessonTimes: 'HH:MM–HH:MM').
        // Никаких 09:00-заглушек: нет времени слота — не закрепляем.
        const start = (((state.data.lessonTimes || {})[day] || '').match(/\d{1,2}:\d{2}/) || [])[0];
        if (!start) { toast('У этого дня нет слота занятия'); return; }
        try {
            await api('pin', { group_lesson_id: glid, scheduled_at: `${day} ${start}:00` });
            toast(`Тема закреплена на ${day} ${start}`);
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
            ${icoCalendarBoard(34)}
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
    return emptyState('prof-ktp', icoCalendarBoard(34), 'Нет групп', 'За вами пока не закреплены группы.');
}

function errorHtml(msg) {
    return emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить КТП', msg || '', true);
}
