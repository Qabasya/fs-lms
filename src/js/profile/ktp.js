/* ══════════════════════════════════════════════════════════════════════
   КТП / Расписание — реальные данные через AJAX (Эпик 1).
   Источник: window.fsProfile.{groups, schedule:{nonce,actions}, ajax.url}.
   getCalendar → банк тем + календарь; drag → pin_lesson; «Распределить» → reflow.

   Ядро: общий стейт (root/state/api/coursesApi), загрузка/рендер групповой
   доски, drag-and-drop, reflow/публикация. Вынесено в ktp/:
   ktp-calendar-model (чистые вычисления), ktp-templates (HTML-шаблоны),
   ktp-popovers (поповеры темы), ktp-individual (инд. занятия).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, plural, fmtDayMonth } from './utils.js';
import { icoLock, icoSwap, icoChevronLeft, icoChevronRight, icoAlert } from '../common/icons.js';
import { confirmDialog } from '../common/components/confirm-dialog.js';
import { createApi } from './api.js';
import { DOW_RU, MONTHS_RU } from './constants.js';
import { groupPickerBtnHtml, openGroupPicker } from './picker.js';
import { computeMonths, initialCursor, shiftMonth } from './ktp/ktp-calendar-model.js';
import { themeCardHtml, placedThemeHtml, openProgramHtml, emptyStateHtml, noGroupsHtml, errorHtml } from './ktp/ktp-templates.js';
import { attachDeadlinesClick, attachPlacedThemeClick, attachRecordingClick, attachThemeActionsClick } from './ktp/ktp-popovers.js';
import { INDI_ID, loadIndividual } from './ktp/ktp-individual.js';

let root = null;
let state = null;
let api = null;
let coursesApi = null;
/* Контекст модуля инд. занятий: общий стейт + колбек меню групп (см. ktp-individual). */
let indiCtx = null;

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
    indiCtx = { root, state, api, openGroupMenu };

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
/* Хоть одна тема уже поставлена на дату — «Распределить» тогда не имеет смысла,
   вместо неё показываем «Отменить распределение». */
function hasScheduledThemes() { return (state.data.themes || []).some(t => t.scheduled_at); }

/* ── AJAX ─────────────────────────────────────────────────────────────── */
async function loadCalendar() {
    try {
        state.data = await api('getCalendar', { group_id: state.groupId });
    } catch (e) {
        root.innerHTML = errorHtml(e.message);
        return;
    }
    state.months = computeMonths(state.data.period);
    state.cursor = initialCursor(state.data.themes, state.months);
    render();
}

/* ── Render ───────────────────────────────────────────────────────────── */
function render() {
    const g = currentGroup();
    const assigned = state.data.assigned;
    const locked = isLocked();
    // Эпик 15: открытая группа — расписания нет, программа опубликована целиком.
    // Вместо КТП-доски (drag-drop/reflow/publish) — программа списком.
    const open = !!state.data.open;

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
            ${assigned && !open ? `
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
                ${hasScheduledThemes() ? `
                <button class="prof-btn prof-btn-sm" id="ktpUnschedule">Отменить распределение</button>` : `
                <button class="prof-btn prof-btn-sm prof-btn-primary" id="ktpReflow">
                    ${icoSwap(15)}
                    Распределить
                </button>`}
                <button class="prof-btn prof-btn-sm" id="ktpPublish">Опубликовать</button>`}` : ''}
        </div>

        ${assigned && !open ? overflowBannerHtml(state.data) : ''}

        ${assigned ? (open ? openProgramHtml(state.data.themes || []) : `
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
        </div>`) : emptyStateHtml(g)}
    </div>`;

    document.getElementById('ktpGroupBtn').onclick = openGroupMenu;

    if (assigned && !open) {
        if (locked) {
            document.getElementById('ktpUnpublish').onclick = doUnpublish;
        } else {
            if (hasScheduledThemes()) {
                document.getElementById('ktpUnschedule').onclick = doUnschedule;
            } else {
                document.getElementById('ktpReflow').onclick = doReflow;
            }
            document.getElementById('ktpPublish').onclick = doPublish;
        }
        document.getElementById('ktpPrev').onclick = () => shiftMonthBy(-1);
        document.getElementById('ktpNext').onclick = () => shiftMonthBy(1);
        renderBank();
        renderCalendar();
    } else if (!assigned) {
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
        toast(e.message, 'error');
    }

    sel.addEventListener('change', () => { btn.disabled = !sel.value; });
    btn.addEventListener('click', async () => {
        if (!sel.value) { return; }
        btn.disabled = true;
        try {
            const res = await coursesApi('assignCourse', { group_id: state.groupId, course_id: sel.value });
            const warnings = (res && res.warnings) || [];
            toast(warnings.length
                ? `Курс назначен. Внимание: ${warnings.join('; ')}`
                : 'Курс назначен', warnings.length ? 'error' : 'ok');
            await loadCalendar();
        } catch (e) { toast(e.message, 'error'); btn.disabled = false; }
    });
}

/**
 * Этап 2 (Tasks.md): переполнение периода — тем в курсе больше, чем занятий в периоде.
 * Живёт в payload getCalendar() (slots_total/unplaced), не только в тосте doReflow() —
 * баннер переживает перезагрузку страницы.
 */
function overflowBannerHtml(data) {
    const unplaced = data.unplaced || 0;
    if (!unplaced) return '';
    const slots = data.slots_total || 0;
    const total = slots + unplaced;
    return `
    <div class="ktp-overflow-banner">
        ${icoAlert(16)}
        <span>В периоде ${slots} ${plural(slots, 'занятие', 'занятия', 'занятий')}, в курсе ${total} ${plural(total, 'тема', 'темы', 'тем')}.
        ${unplaced} ${plural(unplaced, 'тема', 'темы', 'тем')} не ${plural(unplaced, 'помещается', 'помещаются', 'помещаются')} — ${unplaced === 1 ? 'её' : 'их'} можно открыть только вне расписания.</span>
    </div>`;
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
            ${dayThemes.map(t => placedThemeHtml(t, state.data.video_enabled)).join('')}
        </div>`;
    }

    const grid = document.getElementById('ktpGrid');
    grid.innerHTML = cells;
    // Этап 2 (★): клик по карточке занятия — переход в плеер курса (teacher-режим).
    // Навигация к контенту, не правка структуры/расписания — доступна даже при lock КТП.
    grid.querySelectorAll('.placed-theme').forEach(el => attachPlacedThemeClick(el, () => state.data.themes || []));
    // T12.3: дедлайны — delivery, не структура/расписание — доступны даже при lock КТП (T1.8).
    grid.querySelectorAll('.pt-deadlines').forEach(el => attachDeadlinesClick(el, api));
    // Индикатор записи занятия — тоже ведёт в плеер (Этап 2, ★); тоже delivery, доступен даже при lock КТП.
    grid.querySelectorAll('.pt-recording').forEach(el => attachRecordingClick(el, api, loadCalendar));
    if (!isLocked()) {
        grid.querySelectorAll('.kal-cell[data-lesson="1"]').forEach(attachDrop);
        grid.querySelectorAll('.placed-theme[draggable="true"]').forEach(attachDrag);
        // T12.6: «Продолжить на другую дату» — структурное изменение, блокируется lock КТП.
        grid.querySelectorAll('.pt-more').forEach(el => attachThemeActionsClick(el, api, loadCalendar));
    }
}

/* ── Interactions ─────────────────────────────────────────────────────── */
function shiftMonthBy(d) {
    state.cursor = shiftMonth(state.cursor, state.months.length, d);
    renderCalendar();
}

function openGroupMenu() {
    openGroupPicker(document.getElementById('ktpGroupBtn'), state.groups, state.groupId, id => {
        state.groupId = id;
        if (INDI_ID === id) { loadIndividual(indiCtx); } else { loadCalendar(); }
    }, [{ v: String(INDI_ID), label: 'Индивидуальные занятия', swatchClass: 'chip-indi', chip: 'Инд' }]);
}

async function doReflow() {
    try {
        const res = await api('reflow', { group_id: state.groupId });
        const conflicts = res && res.room_conflicts ? +res.room_conflicts : 0;
        const unplaced = res && res.unplaced ? +res.unplaced : 0;
        const parts = ['Темы распределены' + (conflicts === 0 && unplaced === 0 ? ' автоматически' : '')];
        if (unplaced > 0) parts.push(`${unplaced} ${plural(unplaced, 'тема', 'темы', 'тем')} не ${plural(unplaced, 'поместилась', 'поместились', 'поместились')} — откройте вне расписания`);
        if (conflicts > 0) parts.push(`кабинет снят с ${conflicts} занятий (был занят)`);
        toast(parts.join(' · '));
        await loadCalendar();
    } catch (e) {
        toast(e.message, 'error');
    }
}

async function doUnschedule() {
    const ok = await confirmDialog('Отменить распределение? Темы вернутся в «Темы курса» без дат.', 'Отменить распределение', 'Не отменять');
    if (!ok) { return; }
    try {
        await api('unschedule', { group_id: state.groupId });
        toast('Распределение отменено — темы возвращены в пул');
        await loadCalendar();
    } catch (e) {
        toast(e.message, 'error');
    }
}

/* T1.8: публикация/снятие публикации КТП. */
async function doPublish() {
    try {
        await api('publish', { group_id: state.groupId });
        toast('КТП опубликована — редактирование заблокировано');
        await loadCalendar();
    } catch (e) { toast(e.message, 'error'); }
}

async function doUnpublish() {
    try {
        await api('unpublish', { group_id: state.groupId });
        toast('Публикация снята — редактирование доступно');
        await loadCalendar();
    } catch (e) { toast(e.message, 'error'); }
}

/* ── Drag-and-drop ────────────────────────────────────────────────────── */
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
        if (!start) { toast('У этого дня нет слота занятия', 'error'); return; }
        // Этап 3: drop строго закрепляет тему на дату, вытесняя ту, что там стояла
        // (если была) — вернётся в пул. Имя темы для тоста берём из уже загруженного
        // календаря (state.data), запрос AJAX это не меняет.
        const dragged = (state.data.themes || []).find(t => String(t.group_lesson_id) === String(glid));
        const displaced = (state.data.themes || [])
            .find(t => t.group_lesson_id !== dragged?.group_lesson_id && (t.scheduled_at || '').slice(0, 10) === day);
        try {
            await api('pin', { group_lesson_id: glid, scheduled_at: `${day} ${start}:00` });
            const draggedLabel = dragged ? `Тема ${dragged.n}` : 'Тема';
            toast(displaced
                ? `${draggedLabel} закреплена на ${fmtDayMonth(day)} · тема ${displaced.n} возвращена в пул`
                : `${draggedLabel} закреплена на ${fmtDayMonth(day)}`);
            await loadCalendar();
        } catch (err) {
            toast(err.message, 'error');
        }
    });
}
