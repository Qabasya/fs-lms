/* ══════════════════════════════════════════════════════════════════════
   Экран «Назначить экзамен» (этап 4.2): сеансы назначаются в месячном календаре на общей модели КТП.
   Вместо группы — предмет, вместо тем — варианты работ, вместо урока — сеанс. Разметка — существующая КТП:
   prof-ktp / kp-btn / prof-dot / prof-kal / placed-theme / prof-ktp-empty.

   Календарь показывает только месяцы периода проведения; выходные не скрываются; период может идти через границу месяцев и года.
   Сеанс добавляется кнопкой «+ Сеанс» в ячейке дня периода: открывается форма сеанса (exam-session-form.js), вариант выбирается в ней.
   Банка вариантов и легенды нет — календарь занимает всю ширину.
   Права проверяет сервер; клиент только прячет недоступное. Сеть — createApi(window.fsProfile.exams).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState, openCtxMenu, openCtxMenuRaw, closeCtxMenu, fmtDayMonth, chipHtml } from '../utils.js';
import { icoChevronLeft, icoChevronRight, icoCalendarBoard, icoAlert, icoCaret, icoPlus } from '../../common/icons.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { createApi } from '../api.js';
import { DOW_RU, MONTHS_RU } from '../constants.js';
import { computeMonths, shiftMonth, monthCells } from '../ktp/ktp-calendar-model.js';
import {
    examConfig, examSubjects, currentSubject, setCurrentSubject, subjectPickerHtml, wireSubjectPicker, noSubjectsHtml,
    statusPillHtml, sessionsByDate, inPeriod,
} from './exam-common.js';
import { openSessionForm } from './exam-session-form.js';
import { openEventForm, openCancelForm } from './exam-event-form.js';
import { openSourcesDrawer, gradeOfDirection } from './exam-sources.js';

const PUBLISH_TEXT = 'После публикации ученики предмета увидят экзамен и смогут записываться с даты открытия записи.';
const DELETE_TEXT = 'Проведение будет удалено вместе с сеансами и ссылками школ без возможности восстановления. Это возможно, только пока в нём нет записей участников.';
const NEW_EVENT = 'new';

let root = null;
let api = null;
let cfg = null;
let plan = null;
let months = [];
let cursor = 0;

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
            ${chipHtml('kp-chip', currentSubject(), subject ? subject.name : '')}
            <span class="kp-txt">${label}</span>
            ${icoCaret(12, 'kp-caret')}
        </button></div>`;
}

function headHtml() {
    const e = plan.event;
    const subjects = examSubjects();
    const canPublish = e && 'draft' === e.status && e.sessions.length > 0;
    return `<div class="prof-ktp-head exam-plan-head">
        <div class="prof-ktp-pickers exam-plan-pickers">
            ${subjectPickerHtml(subjects, currentSubject(), 'examPlanSubjectBtn')}
            ${eventPickerHtml()}
        </div>
        <span class="prof-spacer"></span>
        ${e ? statusPillHtml(e.status, e.status_label) : ''}
        ${isEditable() ? '<button type="button" class="prof-btn prof-btn-sm" id="examSettings">Настройки проведения</button>' : ''}
        ${isEditable() && cfg.canManageGuests && e && e.guest_registration_enabled ? '<button type="button" class="prof-btn prof-btn-sm" id="examLinks">Управление ссылками</button>' : ''}
        ${e && 'draft' === e.status ? `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" id="examPublish" ${canPublish ? '' : 'disabled title="Добавьте хотя бы один сеанс"'}>Опубликовать</button>` : ''}
        ${e ? '<button type="button" class="prof-icon-ghost" id="examActions" aria-label="Действия">⋮</button>' : ''}
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

function sessionHtml(s) {
    const cancelled = 'cancelled' === s.status;
    // Полоска слева: завершённый — жёлтая, отменённый — красная, остальные — зелёная плана.
    const guestsOnly = false === s.visible_to_students;
    const state = (cancelled ? ' is-cancelled' : ('completed' === s.status ? ' is-completed' : '')) + (guestsOnly ? ' is-guests-only' : '');
    return `<div class="placed-theme exam-session${state}" data-sid="${s.id}" role="button" tabindex="0">
        <span class="pt-title">${esc(s.time_start)} · ${s.occupied}/${s.capacity}</span>
        <span class="pt-meta">${esc(s.assessment_title)}</span>
        <span class="pt-meta">${cancelled ? 'отменён' : esc(s.room_name)}${guestsOnly ? ' · только гости' : ''}</span>
    </div>`;
}

function render() {
    const e = plan.event;
    root.innerHTML = `<div class="prof-ktp">${headHtml()}${e ? bannersHtml() + boardHtml() : emptyEventsHtml()}</div>`;

    wireSubjectPicker('examPlanSubjectBtn', examSubjects(), currentSubject(), key => {
        setCurrentSubject(key);
        load(0);
    });
    document.getElementById('examEventBtn').onclick = openEventMenu;
    const create = document.getElementById('examCreate');
    if (create) { create.onclick = () => openNewEventForm(create); }

    if (!e) { return; }
    const settings = document.getElementById('examSettings');
    if (settings) { settings.onclick = () => openSettings(settings); }
    const links = document.getElementById('examLinks');
    if (links) { links.onclick = openLinks; }
    const publish = document.getElementById('examPublish');
    if (publish) { publish.onclick = doPublish; }
    const actions = document.getElementById('examActions');
    if (actions) { actions.onclick = () => openActionsMenu(actions); }

    document.getElementById('examPrev').onclick = () => shiftBy(-1);
    document.getElementById('examNext').onclick = () => shiftBy(1);
    renderCalendar();
}

function boardHtml() {
    return `<div class="prof-ktp-grid prof-ktp-grid--single">
        <div class="prof-kal">
            <div class="kal-head">
                <button type="button" class="prof-icon-ghost" id="examPrev" aria-label="Предыдущий месяц">${icoChevronLeft(18)}</button>
                <div class="kal-month" id="examMonth"></div>
                <button type="button" class="prof-icon-ghost" id="examNext" aria-label="Следующий месяц">${icoChevronRight(18)}</button>
                <span class="prof-spacer"></span>
                <span class="kal-hint">${isEditable() ? 'Нажмите «+ Сеанс» в нужный день' : 'Проведение закрыто — изменять нельзя'}</span>
            </div>
            <div class="kal-grid-wrap">
                <div class="kal-dow">${DOW_RU.map(d => `<span>${d}</span>`).join('')}</div>
                <div class="kal-grid kal-grid--weeks" id="examGrid"></div>
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

    // Месяц по неделям: вне периода и без сеансов — сжата до строки с числами (off); в периоде без сеансов — 60–80px (empty);
    // с сеансами — растёт по содержимому, два сеанса в день делают неделю выше (busy).
    const days = monthCells(y, m);
    while (days.length % 7) { days.push({ type: 'empty' }); }

    let weeks = '';
    for (let i = 0; i < days.length; i += 7) {
        const week = days.slice(i, i + 7);
        const dayCells = week.filter(c => 'day' === c.type);
        const hasSessions = dayCells.some(c => (byDate[c.date] || []).length);
        const kind = hasSessions ? 'busy' : (dayCells.some(c => inPeriod(c.date, event)) ? 'empty' : 'off');
        const cells = week.map(cell => {
            if ('empty' === cell.type) { return '<div class="kal-cell empty"></div>'; }
            const inside = inPeriod(cell.date, event);
            return `<div class="kal-cell${inside ? '' : ' no-lesson'}" data-day="${cell.date}">
                <div class="kal-date"><span class="kd-num">${cell.day}</span>
                ${inside && editable ? `<button type="button" class="exam-add" data-day="${cell.date}" aria-label="Добавить сеанс ${cell.date}">${icoPlus(11)} Сеанс</button>` : ''}</div>
                ${(byDate[cell.date] || []).map(sessionHtml).join('')}
            </div>`;
        }).join('');
        weeks += `<div class="kal-week kal-week--${kind}">${cells}</div>`;
    }

    const grid = document.getElementById('examGrid');
    grid.innerHTML = weeks;

    grid.querySelectorAll('.exam-add').forEach(btn => {
        btn.onclick = () => openSession(btn, { date: btn.dataset.day });
    });
    grid.querySelectorAll('.exam-session').forEach(el => {
        const open = () => openSession(el, null, event.sessions.find(s => String(s.id) === el.dataset.sid));
        el.addEventListener('click', open);
        el.addEventListener('keydown', ev => { if ('Enter' === ev.key || ' ' === ev.key) { ev.preventDefault(); open(); } });
    });
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

/** Панель «Управление ссылками»: школы, их ссылки и открытая запись; класс определяется направлением основного варианта. */
function openLinks() {
    const main = plan.variants.find(v => String(v.id) === String(plan.event.default_assessment_id));
    openSourcesDrawer({ api, event: plan.event, grade: gradeOfDirection(main && main.direction) });
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
    const items = [];
    if (isEditable()) { items.push('<div class="ctx-item danger" data-act="cancel">Отменить проведение</div>'); }
    items.push('<div class="ctx-item danger" data-act="delete">Удалить проведение</div>');
    openCtxMenuRaw(items.join(''), anchor, null);

    const bind = (act, handler) => {
        const item = document.querySelector(`#profCtxMenu [data-act="${act}"]`);
        if (item) { item.addEventListener('click', () => { closeCtxMenu(); handler(); }); }
    };
    bind('cancel', () => openCancelForm({ api, anchor, event: plan.event, onDone: () => load(plan.event.id, true) }));
    bind('delete', doDelete);
}

/** Удаление доступно, пока в проведении нет записей участников: иначе сервер откажет и подскажет отмену. */
async function doDelete() {
    if (!await confirmDialog(DELETE_TEXT, 'Удалить проведение', 'Не удалять')) { return; }
    try {
        await api('deleteEvent', { event_id: plan.event.id, version: plan.event.version });
        toast('Проведение удалено');
        await load(0);
    } catch (err) {
        toast(err.message, 'error');
        if ('X-STALE' === err.code) { await reload(); }
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
