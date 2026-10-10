/* ══════════════════════════════════════════════════════════════════════
   Экран «Назначить экзамен» (этап 4.2): сеансы назначаются в месячном календаре на общей модели КТП.
   Вместо группы — предмет, вместо тем — варианты работ, вместо урока — сеанс. Разметка — существующая КТП:
   prof-ktp / kp-btn / prof-dot / prof-theme-bank / prof-theme-card / prof-kal / placed-theme / prof-ktp-empty.

   Календарь показывает только месяцы периода проведения; выходные не скрываются; период может идти через границу месяцев и года.
   Перетаскивание варианта на день ничего не сохраняет: оно открывает форму сеанса (exam-session-form.js), как и кнопка «+ Сеанс».
   На телефоне (≤ 720 px) перетаскивания нет — кнопка «+ Сеанс» есть в каждой ячейке периода и на компьютере тоже.
   Права проверяет сервер; клиент только прячет недоступное. Сеть — createApi(window.fsProfile.exams).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState, openCtxMenu, openCtxMenuRaw, closeCtxMenu, fmtDayMonth, chipBg, shortName } from '../utils.js';
import { icoChevronLeft, icoChevronRight, icoCalendarBoard, icoAlert, icoGrip, icoCaret, icoPlus } from '../../common/icons.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { createApi } from '../api.js';
import { DOW_RU, MONTHS_RU } from '../constants.js';
import { computeMonths, shiftMonth, monthCells } from '../ktp/ktp-calendar-model.js';
import {
    examConfig, examSubjects, currentSubject, setCurrentSubject, subjectPickerHtml, wireSubjectPicker, noSubjectsHtml,
    statusPillHtml, isPhone, sessionsByDate, inPeriod,
} from './exam-common.js';
import { openSessionForm } from './exam-session-form.js';
import { openEventForm, openCancelForm } from './exam-event-form.js';

const PUBLISH_TEXT = 'После публикации ученики предмета увидят экзамен и смогут записываться с даты открытия записи.';
const NEW_EVENT = 'new';

let root = null;
let api = null;
let cfg = null;
let plan = null;
let months = [];
let cursor = 0;
let dragVariant = null;

export function renderExamPlan(r) {
    root = r;
    cfg = examConfig();
    if (!cfg) {
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Данные экзаменов недоступны', '', true);
        return;
    }
    if (!examSubjects().length) {
        root.innerHTML = noSubjectsHtml();
        return;
    }
    api = createApi(cfg);
    load(0);
}

/* ── Данные ───────────────────────────────────────────────────────────── */
async function load(eventId, keepMonth = false) {
    const shown = keepMonth && months[cursor];
    try {
        plan = await api('getPlan', { subject_key: currentSubject(), event_id: eventId || 0 });
    } catch (e) {
        plan = null;
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить экзамены', e.message, true);
        return;
    }
    setupMonths(shown);
    render();
}

const reload = () => load(plan && plan.event ? plan.event.id : 0, true);

function setupMonths(shown) {
    const event = plan.event;
    months = event ? computeMonths({ start_date: event.period_from, end_date: event.period_to }) : [];
    cursor = 0;
    if (!months.length) { return; }

    const wanted = shown
        || monthOf(new Date().toISOString().slice(0, 7))
        || (event.sessions.length ? monthOf(event.sessions[0].date.slice(0, 7)) : null);
    const idx = wanted ? months.findIndex(mm => mm.y === wanted.y && mm.m === wanted.m) : -1;
    cursor = idx >= 0 ? idx : 0;
}

/** '2026-10' → {y: 2026, m: 9} (m — 0-based, как в computeMonths). */
function monthOf(ym) {
    const [y, m] = ym.split('-').map(Number);
    return { y, m: m - 1 };
}

const isEditable = () => !!plan.event && ('draft' === plan.event.status || 'published' === plan.event.status);

/* ── Разметка ─────────────────────────────────────────────────────────── */
function eventPickerHtml() {
    const e = plan.event;
    const label = e ? `${esc(e.title)} · ${fmtDayMonth(e.period_from)}–${fmtDayMonth(e.period_to)}` : 'Нет проведений';
    const subject = examSubjects().find(s => s.key === currentSubject());
    return `<div class="prof-ktp-pick"><span class="kp-label">Проведение</span>
        <button type="button" class="kp-btn" id="examEventBtn">
            <span class="kp-chip ${chipBg(currentSubject())}">${esc(shortName(subject ? subject.name : ''))}</span>
            <span class="kp-txt">${label}</span>
            ${icoCaret(12, 'kp-caret')}
        </button></div>`;
}

function headHtml() {
    const e = plan.event;
    const subjects = examSubjects();
    const canPublish = e && 'draft' === e.status && e.sessions.length > 0;
    return `<div class="prof-ktp-head">
        <div class="prof-ktp-pickers">
            ${subjectPickerHtml(subjects, currentSubject())}
            ${eventPickerHtml()}
        </div>
        <span class="prof-spacer"></span>
        ${e ? `<div class="prof-ktp-legend"><span class="kl"><span class="prof-dot prof-dot-good"></span>Сеанс экзамена</span></div>
        ${statusPillHtml(e.status, e.status_label)}` : ''}
        ${isEditable() ? '<button type="button" class="prof-btn prof-btn-sm" id="examSettings">Настройки проведения</button>' : ''}
        ${e && 'draft' === e.status ? `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" id="examPublish" ${canPublish ? '' : 'disabled title="Добавьте хотя бы один сеанс"'}>Опубликовать</button>` : ''}
        ${isEditable() ? '<button type="button" class="prof-icon-ghost" id="examActions" aria-label="Действия">⋮</button>' : ''}
    </div>`;
}

function emptyEventsHtml() {
    return `<div class="prof-ktp-empty">
        <div class="ke-ico">${icoCalendarBoard(34)}</div>
        <h3>Проведений пока нет</h3>
        <p>Создайте проведение: задайте даты, затем добавьте сеансы в календаре.</p>
        <div class="ke-assign"><button type="button" class="prof-btn prof-btn-primary" id="examCreate">Создать проведение</button></div>
    </div>`;
}

function bannersHtml() {
    const items = [];
    if (!plan.variants.length) { items.push('Нет опубликованных вариантов этого предмета.'); }
    if (!plan.rooms.length) { items.push('Укажите вместимость кабинетов в „Настройки → Кабинеты“.'); }
    return items.map(t => `<div class="ktp-overflow-banner">${icoAlert(16)}<span>${esc(t)}</span></div>`).join('');
}

function variantCardHtml(v, index) {
    const draggable = isEditable() && !isPhone();
    const dir = 'ege' === v.direction ? 'ЕГЭ' : ('oge' === v.direction ? 'ОГЭ' : '');
    return `<div class="prof-theme-card" ${draggable ? 'draggable="true"' : ''} data-vid="${v.id}">
        <span class="tc-num">${index + 1}</span>
        <div class="tc-body">
            <div class="tc-title">${esc(v.title)}</div>
            <div class="tc-meta">${dir ? `<span>${dir}</span>` : ''}<span>${v.duration_minutes} мин</span></div>
        </div>
        ${draggable ? `<span class="tc-grip">${icoGrip(14)}</span>` : ''}
    </div>`;
}

function sessionHtml(s) {
    const cancelled = 'cancelled' === s.status;
    return `<div class="placed-theme exam-session${cancelled ? ' is-draft' : ''}" data-sid="${s.id}" role="button" tabindex="0">
        <span class="pt-title">${esc(s.time_start)} · ${s.occupied}/${s.capacity}</span>
        <span class="pt-meta">${esc(s.assessment_title)}</span>
        <span class="pt-meta">${cancelled ? 'отменён' : esc(s.room_name)}</span>
    </div>`;
}

function render() {
    const e = plan.event;
    root.innerHTML = `<div class="prof-ktp">${headHtml()}${e ? bannersHtml() + boardHtml() : emptyEventsHtml()}</div>`;

    wireSubjectPicker('examSubjectBtn', examSubjects(), currentSubject(), key => {
        setCurrentSubject(key);
        load(0);
    });
    document.getElementById('examEventBtn').onclick = openEventMenu;
    const create = document.getElementById('examCreate');
    if (create) { create.onclick = () => openNewEventForm(create); }

    if (!e) { return; }
    const settings = document.getElementById('examSettings');
    if (settings) { settings.onclick = () => openSettings(settings); }
    const publish = document.getElementById('examPublish');
    if (publish) { publish.onclick = doPublish; }
    const actions = document.getElementById('examActions');
    if (actions) { actions.onclick = () => openActionsMenu(actions); }

    document.getElementById('examPrev').onclick = () => shiftBy(-1);
    document.getElementById('examNext').onclick = () => shiftBy(1);
    wireBank();
    renderCalendar();
}

function boardHtml() {
    return `<div class="prof-ktp-grid">
        <div class="prof-theme-bank">
            <div class="tb-head"><h3>Экзаменационные работы</h3><span class="tbh-count">${isEditable() && !isPhone() ? 'перетащите на дату' : 'вариант выбирается в форме сеанса'}</span></div>
            <div class="prof-theme-list" id="examBank">${plan.variants.length
                ? plan.variants.map(variantCardHtml).join('')
                : '<div class="tb-empty">Нет опубликованных вариантов этого предмета.</div>'}</div>
        </div>
        <div class="prof-kal">
            <div class="kal-head">
                <button type="button" class="prof-icon-ghost" id="examPrev" aria-label="Предыдущий месяц">${icoChevronLeft(18)}</button>
                <div class="kal-month" id="examMonth"></div>
                <button type="button" class="prof-icon-ghost" id="examNext" aria-label="Следующий месяц">${icoChevronRight(18)}</button>
                <span class="prof-spacer"></span>
                <span class="kal-hint">${isEditable() ? 'Нажмите «+ Сеанс» в нужный день или перетащите вариант на дату' : 'Проведение закрыто — изменять нельзя'}</span>
            </div>
            <div class="kal-grid-wrap">
                <div class="kal-dow">${DOW_RU.map(d => `<span>${d}</span>`).join('')}</div>
                <div class="kal-grid" id="examGrid"></div>
            </div>
        </div>
    </div>`;
}

function renderCalendar() {
    if (!months.length) { return; }
    const { y, m } = months[cursor];
    document.getElementById('examMonth').textContent = `${MONTHS_RU[m]} ${y}`;
    document.getElementById('examPrev').disabled = cursor <= 0;
    document.getElementById('examNext').disabled = cursor >= months.length - 1;

    const event = plan.event;
    const byDate = sessionsByDate(event.sessions);
    const editable = isEditable();

    let cells = '';
    for (const cell of monthCells(y, m)) {
        if ('empty' === cell.type) { cells += '<div class="kal-cell empty"></div>'; continue; }
        const inside = inPeriod(cell.date, event);
        cells += `<div class="kal-cell${inside ? '' : ' no-lesson'}" data-day="${cell.date}">
            <div class="kal-date"><span class="kd-num">${cell.day}</span></div>
            ${(byDate[cell.date] || []).map(sessionHtml).join('')}
            ${inside && editable ? `<button type="button" class="exam-add" data-day="${cell.date}" aria-label="Добавить сеанс ${cell.date}">${icoPlus(11)} Сеанс</button>` : ''}
        </div>`;
    }

    const grid = document.getElementById('examGrid');
    grid.innerHTML = cells;

    grid.querySelectorAll('.exam-add').forEach(btn => {
        btn.onclick = () => openSession(btn, { date: btn.dataset.day });
    });
    grid.querySelectorAll('.exam-session').forEach(el => {
        const open = () => openSession(el, null, event.sessions.find(s => String(s.id) === el.dataset.sid));
        el.addEventListener('click', open);
        el.addEventListener('keydown', ev => { if ('Enter' === ev.key || ' ' === ev.key) { ev.preventDefault(); open(); } });
    });
    if (editable && !isPhone()) {
        grid.querySelectorAll('.kal-cell[data-day]:not(.no-lesson)').forEach(attachDrop);
    }
}

/* ── Действия ─────────────────────────────────────────────────────────── */
function shiftBy(d) {
    cursor = shiftMonth(cursor, months.length, d);
    renderCalendar();
}

function openSession(anchor, fixed, edit = null) {
    openSessionForm({
        api, anchor, event: plan.event, variants: plan.variants, rooms: plan.rooms, fixed: fixed || {}, edit, onSaved: reload,
    });
}

function openNewEventForm(anchor) {
    openEventForm({
        api, anchor, subjectKey: currentSubject(), event: null, variants: plan.variants, canManageGuests: !!cfg.canManageGuests, launch: plan.launch_new,
        onSaved: id => load(id),
    });
}

function openSettings(anchor) {
    openEventForm({
        api, anchor, subjectKey: currentSubject(), event: plan.event, variants: plan.variants, canManageGuests: !!cfg.canManageGuests, launch: plan.launch,
        onSaved: id => load(id, true),
    });
}

function openEventMenu() {
    const anchor = document.getElementById('examEventBtn');
    const items = plan.events.map(e => ({
        v: String(e.id),
        label: `${e.title} · ${fmtDayMonth(e.period_from)}–${fmtDayMonth(e.period_to)}`,
        active: plan.event && e.id === plan.event.id,
    }));
    items.push({ v: NEW_EVENT, label: '+ Новое проведение' });
    openCtxMenu(anchor, items, v => {
        if (NEW_EVENT === v) { openNewEventForm(anchor); return; }
        if (!plan.event || String(plan.event.id) !== v) { load(Number(v)); }
    });
}

function openActionsMenu(anchor) {
    openCtxMenuRaw('<div class="ctx-item danger" data-act="cancel">Отменить проведение</div>', anchor, null);
    const item = document.querySelector('#profCtxMenu [data-act="cancel"]');
    if (item) {
        item.addEventListener('click', () => {
            closeCtxMenu();
            openCancelForm({ api, anchor, event: plan.event, onDone: () => load(plan.event.id, true) });
        });
    }
}

async function doPublish() {
    if (!await confirmDialog(PUBLISH_TEXT, 'Опубликовать', 'Не публиковать')) { return; }
    try {
        await api('publishEvent', { event_id: plan.event.id, version: plan.event.version });
        toast('Проведение опубликовано');
        await reload();
    } catch (err) {
        toast(err.message, 'error');
        if ('X-STALE' === err.code) { await reload(); }
    }
}

/* ── Перетаскивание варианта на день ──────────────────────────────────── */
function wireBank() {
    document.querySelectorAll('#examBank .prof-theme-card[draggable="true"]').forEach(el => {
        el.addEventListener('dragstart', e => {
            dragVariant = el.dataset.vid;
            el.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', dragVariant);
        });
        el.addEventListener('dragend', () => {
            dragVariant = null;
            el.classList.remove('dragging');
            document.querySelectorAll('.kal-cell.drop-ok').forEach(c => c.classList.remove('drop-ok'));
        });
    });
}

function attachDrop(cell) {
    cell.addEventListener('dragover', e => {
        if (!dragVariant) { return; }
        e.preventDefault();
        cell.classList.add('drop-ok');
    });
    cell.addEventListener('dragleave', () => cell.classList.remove('drop-ok'));
    cell.addEventListener('drop', e => {
        e.preventDefault();
        cell.classList.remove('drop-ok');
        if (!dragVariant) { return; }
        const assessmentId = Number(dragVariant);
        dragVariant = null;
        // Само перетаскивание ничего не сохраняет: открывается та же форма сеанса с подставленными датой и вариантом.
        openSession(cell, { date: cell.dataset.day, assessmentId });
    });
}
