/* ══════════════════════════════════════════════════════════════════════
   Экран «Мои экзамены» ученика и родителя (этап 5).
   Сервер отдаёт готовое состояние карточки и список разрешённых действий —
   клиент ничего не вычисляет по датам. Родитель видит те же карточки: из
   действий ему доступны только «Результаты» (запрет проверяется на сервере,
   кнопки лишь не рисуются).
   Разметка — из «Моих курсов»: sc-tabs / sc-tab / sc-hero / sc-hint / sc-notice /
   sc-row / sc-pill. Карусель сеансов — та же оболочка, что у вкладок (course-tabs.js).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, fmtDayMonth, chipBg, chipSoft, plural } from '../utils.js';
import { applyProgress } from '../../common/utils.js';
import { createApi } from '../api.js';
import { courseTabsShell, syncCourseTabs } from '../course-tabs.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { childBar, wireChild, getChildId, isParent, onChildChange } from '../learner-child.js';
import { UNIT_STATUS, resultCaption, resultPercent } from './exam-result.js';

const DIRECTION_LABEL = { ege: 'ЕГЭ', oge: 'ОГЭ' };

const ACTION_LABEL = {
    register: 'Записаться',
    change: 'Сменить сеанс',
    cancel: 'Отменить запись',
    start: 'Приступить',
    resume: 'Продолжить',
    results: 'Результаты',
};

/* Состояния, при которых кнопка «Записаться»/«Сменить» раскрывает панель сеансов. */
const SLOT_ACTIONS = ['register', 'change'];

/* Отказы, после которых показанное устарело (место забрали, запись закрыли, вкладка отстала): список перечитывается. */
const REFRESH_CODES = ['X-FULL', 'X-HELD', 'X-CLOSED', 'X-STALE', 'X-STARTED', 'X-NOT-OPEN'];

/* Предупреждения операции записи (поле `warnings` ответа): запись создана, но ученику стоит знать. */
const WARNING_TEXT = {
    lesson_overlap: 'В это время у вас занятие по расписанию.',
};

/* Отказ «место заняли» читается одинаково при любом тексте сервера: выбор сброшен, нужен другой сеанс. */
const FULL_TEXT = 'Это место только что заняли. Выберите другой сеанс.';

/* Кнопка «Приступить» оживает сама: карточка в «registered» перезапрашивается, пока экран на виду. */
const REFRESH_MS = 30000;

let rootEl = null;
let api = null;
let openReviewCb = () => {};
let exams = [];
let activeId = null;
let pendingEventId = null; // карточка, которую просили открыть из расписания (5.5)
let requestKey = null;     // один ключ на одно намерение пользователя: повтор после обрыва идемпотентен
let chosenSessionId = null;
let panelMode = null;      // 'register' | 'change' | null
let generation = 0;        // номер запроса списка: ответ устаревшего запроса экран не перерисовывает
let refreshTimer = null;

/** Ключ идемпотентности: ≤ 64 символов, только [a-z0-9-]. */
function newRequestKey() {
    if (window.crypto?.randomUUID) { return window.crypto.randomUUID(); }
    return 'rk-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

/** Человекочитаемая дата: сервер отдаёт Y-m-d, показываем ДД.ММ. */
function day(iso) { return fmtDayMonth(iso); }

/**
 * @param {HTMLElement} root
 * @param {{openReview?: (eventId: number, anchor?: string) => void}} [options] `openReview` — открыть экран «Результаты».
 */
export function renderLearnerExams(root, { openReview } = {}) {
    rootEl = root;
    openReviewCb = typeof openReview === 'function' ? openReview : () => {};
    const cfg = window.fsProfile?.exams;
    if (!cfg) {
        root.innerHTML = `<div class="prof-dash"><div class="rev-empty">Данные экзаменов недоступны.</div></div>`;
        return;
    }
    api = createApi(cfg);
    root.innerHTML = `<div class="prof-dash"><div class="rev-loading">Загрузка…</div></div>`;
    load();
}

/** Открыть карточку проведения (клик по экзамену в расписании): если экран ещё грузится — запомнить. */
export function openLearnerExam(eventId) {
    pendingEventId = Number(eventId);
    if (rootEl && exams.some(e => e.event_id === pendingEventId)) {
        select(pendingEventId);
        pendingEventId = null;
    }
}

// Родитель переключил ребёнка — перечитать карточки именно его; ответ по прежнему ребёнку отбрасывается.
onChildChange(() => { if (rootEl && api) { load(); } });
document.addEventListener('visibilitychange', () => syncRefreshTimer());

/** Параметры запроса списка: у родителя — выбранный ребёнок. */
function listParams() {
    const childId = getChildId();
    return childId ? { student_person_id: childId } : {};
}

/** Следующий номер запроса: всё, что вернётся по предыдущим, уже не актуально. */
function nextGeneration() {
    generation += 1;
    return generation;
}

function load() {
    const mine = nextGeneration();
    return api('getExams', listParams())
        .then(data => { if (mine === generation) { apply(data); } })
        .catch(e => {
            if (mine !== generation) { return; }
            rootEl.innerHTML = `<div class="prof-dash">${childBar()}<div class="rev-empty">${esc(e.message)}</div></div>`;
            wireChild(rootEl);
        });
}

/** Принять свежий список карточек и перерисовать экран, сохранив выбранное проведение. */
function apply(data) {
    exams = data?.exams || [];
    if (pendingEventId && exams.some(e => e.event_id === pendingEventId)) {
        activeId = pendingEventId;
        pendingEventId = null;
    } else if (!exams.some(e => e.event_id === activeId)) {
        activeId = exams[0]?.event_id ?? null;
    }
    closePanel();
    paint();
    showNotice((data?.warnings || []).map(w => WARNING_TEXT[w]).filter(Boolean).join(' '));
}

function select(eventId) {
    activeId = eventId;
    closePanel();
    paint();
    rootEl.querySelector('#exHero')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function active() { return exams.find(e => e.event_id === activeId) || null; }

/* ── Автообновление ───────────────────────────────────────────────────── */

/** Экран «Мои экзамены» сейчас на виду (экраны кабинета смонтированы все сразу, видим один). */
function screenActive() {
    return !!rootEl?.closest('.prof-screen')?.classList.contains('active');
}

/** Таймер нужен, пока есть карточка «registered», вкладка браузера на виду и никто не выбирает сеанс. */
function syncRefreshTimer() {
    const needed = !document.hidden && exams.some(e => e.state === 'registered');
    if (needed && !refreshTimer) {
        refreshTimer = setInterval(() => {
            if (screenActive() && !panelMode) { load(); }
        }, REFRESH_MS);
    } else if (!needed && refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
}

/* ── Отрисовка ────────────────────────────────────────────────────────── */
function paint() {
    if (!exams.length) {
        rootEl.innerHTML = `<div class="prof-dash">${childBar()}
            <div class="prof-card"><div class="sc-empty">Экзаменов пока нет. Когда преподаватель назначит экзамен, он появится здесь.</div></div>
        </div>`;
        wireChild(rootEl);
        syncRefreshTimer();
        return;
    }

    rootEl.innerHTML = `<div class="prof-dash">
        ${childBar()}
        ${courseTabsShell('exTabs')}
        <div class="prof-card sc-hero" id="exHero"></div>
        <div class="sc-notice exam-notice" id="exNotice" role="alert" hidden></div>
        <div class="prof-card exam-panel" id="exPanel" hidden></div>
        <div class="prof-card exam-units" id="exUnits" hidden></div>
    </div>`;
    wireChild(rootEl);
    paintTabs();
    paintHero();
    paintUnits();
    syncRefreshTimer();
}

function paintTabs() {
    const wrap = rootEl.querySelector('#exTabs');
    wrap.innerHTML = exams.map(e => `<button class="sc-tab${e.event_id === activeId ? ' on' : ''}" data-id="${e.event_id}">
            <span class="sc-chip ${chipBg(e.subject_key)}">${esc(DIRECTION_LABEL[e.direction] || 'Экзамен')}</span>
            <span class="sc-tb"><span class="sc-tname">${esc(e.title)}</span><span class="sc-tsub">${esc(tabSubtitle(e))}</span></span>
        </button>`).join('');
    wrap.querySelectorAll('.sc-tab').forEach(b => b.addEventListener('click', () => select(Number(b.dataset.id))));
    syncCourseTabs(wrap);
}

function tabSubtitle(e) {
    const reg = e.registration;
    switch (e.state) {
        case 'not_open':           return e.registration_opens_at ? `Запись с ${day(e.registration_opens_at)}` : 'Запись не открыта';
        case 'open':               return 'Запись открыта';
        case 'full':               return 'Свободных мест нет';
        case 'closed':             return 'Запись закрыта';
        case 'registered':
        case 'entry_open':         return reg ? `${day(reg.date)}, ${reg.time_start}` : '';
        case 'in_progress':        return 'Выполняется';
        case 'awaiting_approval':  return 'Ожидает утверждения';
        case 'approved':           return resultCaption(e.result) || 'Результат утверждён';
        case 'missed':             return 'Экзамен пропущен';
        case 'cancelled_by_staff': return 'Запись отменена';
        case 'event_cancelled':    return 'Проведение отменено';
        default:                   return '';
    }
}

function paintHero() {
    const e = active();
    const hero = rootEl.querySelector('#exHero');
    const reg = e.registration;
    const meta = [
        reg ? `${day(reg.date)}${reg.weekday ? ` (${reg.weekday})` : ''}, ${reg.time_start}–${reg.time_end}` : '',
        reg?.room || '',
    ].filter(Boolean).map(esc).join('<span class="sc-sep">·</span>');

    const buttons = (e.actions || []).map(a => actionButton(e, a)).join('');
    const parentNotice = isParent()
        ? `<div class="sc-notice">Записывается и сдаёт экзамен сам ученик из своего кабинета.</div>` : '';

    hero.innerHTML = `
        <div class="sc-hero-top">
            <div class="sc-hinfo">
                <span class="sc-code ${chipSoft(e.subject_key)}">${esc(DIRECTION_LABEL[e.direction] || 'Экзамен')}</span>
                <div class="sc-htitle">${esc(e.title)}</div>
                ${meta ? `<div class="sc-hmeta">${meta}</div>` : ''}
                <div class="sc-hmeta"><span class="sc-pill ${statePillClass(e.state)}">${esc(e.state_label)}</span></div>
                ${progressBlock(e)}
            </div>
            <div class="sc-hact">${buttons}${hint(e) ? `<div class="sc-hint">${esc(hint(e))}</div>` : ''}</div>
        </div>
        ${parentNotice}`;

    applyProgress(hero);
    hero.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', () => onAction(e, btn.dataset.action)));
}

/** Утверждённый результат: итог и полоса «первичный / максимум». Числа — только с сервера. */
function progressBlock(e) {
    if (!e.result) { return ''; }
    const pct = resultPercent(e.result);
    return `<div class="sc-hprog">
        <div class="sc-hpl">
            <span class="sc-hpt">${esc(resultCaption(e.result))}</span>
            <span class="sc-hpct">${pct}%</span>
        </div>
        <div class="sc-hpbar"><span class="${chipBg(e.subject_key)}" data-progress="${pct}"></span></div>
    </div>`;
}

/** Перечень заданий утверждённого экзамена: клик ведёт к этому заданию на экране «Результаты». */
function paintUnits() {
    const e = active();
    const box = rootEl.querySelector('#exUnits');
    const units = e?.units || [];
    if (!box || !units.length) { return; }

    box.hidden = false;
    box.innerHTML = `
        <div class="exam-panel-head">
            <div class="exam-panel-title">Задания</div>
            <div class="exam-panel-sub">Нажмите на задание, чтобы открыть разбор.</div>
        </div>
        ${units.map(u => {
            const st = UNIT_STATUS[u.status] || UNIT_STATUS.pending;
            return `<div class="sc-row click" data-anchor="${esc(u.anchor)}" role="button" tabindex="0">
                <span class="sc-num">${esc(u.number)}</span>
                <span class="sc-lb"><span class="sc-ltitle">Задание ${esc(u.number)}</span></span>
                <span class="sc-go">Открыть →</span>
                <span class="sc-pill ${st.pill}">${esc(st.label)}</span>
            </div>`;
        }).join('')}`;

    box.querySelectorAll('.sc-row').forEach(row => {
        const open = () => openReviewCb(e.event_id, row.dataset.anchor);
        row.addEventListener('click', open);
        row.addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(); } });
    });
}

function statePillClass(state) {
    if (['approved'].includes(state)) { return 'done'; }
    if (['open', 'registered', 'entry_open', 'in_progress'].includes(state)) { return 'open'; }
    return 'lock';
}

function actionButton(e, action) {
    // «Приступить»/«Продолжить» ведут на станцию по ссылке из ответа сервера; нет ссылки — кнопка неактивна.
    const needsStation = action === 'start' || action === 'resume';
    const disabled = (e.state === 'not_open' && action === 'register') || (needsStation && !e.station_url);
    const primary = action === 'register' || action === 'start' || action === 'resume';
    return `<button type="button" class="prof-btn${primary ? ' prof-btn-primary' : ''} sc-hbtn${disabled ? ' sc-dis' : ''}" data-action="${action}"${disabled ? ' disabled' : ''}>${esc(ACTION_LABEL[action] || action)}</button>`;
}

function hint(e) {
    const teacher = e.teacher_name || 'преподавателю';
    switch (e.state) {
        case 'not_open':           return e.registration_opens_at ? `Запись откроется ${day(e.registration_opens_at.slice(0, 10))}, ${e.registration_opens_at.slice(11, 16)}` : '';
        case 'registered':         return `Кнопка станет активной в ${e.registration?.time_start || ''}. Смена и отмена записи — до начала выбранного сеанса.`;
        case 'entry_open':         return e.registration?.time_end ? `Начать можно до ${e.registration.time_end}` : 'Начать можно до конца экзамена';
        case 'in_progress':        return e.deadline ? `Завершение в ${e.deadline}` : '';
        case 'full':               return `Свободных мест нет. Обратитесь к преподавателю: ${teacher}.`;
        case 'closed':             return `Запись закрыта. Обратитесь к преподавателю: ${teacher}.`;
        case 'awaiting_approval':  return 'Работа сдана и ожидает утверждения преподавателем.';
        case 'cancelled_by_staff': return e.last_reason ? `Запись отменена. Причина: ${e.last_reason}` : 'Запись отменена.';
        case 'missed':             return e.previous_date ? `Экзамен пропущен, запись на ${day(e.previous_date)} аннулирована.` : 'Экзамен пропущен.';
        case 'event_cancelled':    return `Проведение отменено. Причина: ${e.last_reason || 'не указана'}.`;
        default:                   return '';
    }
}

/** Сообщение об ошибке над панелью: переживает закрытие панели и перерисовку списка. */
function showNotice(text) {
    const box = rootEl?.querySelector('#exNotice');
    if (!box) { return; }
    box.hidden = !text;
    box.textContent = text || '';
}

/* ── Действия ─────────────────────────────────────────────────────────── */
function onAction(e, action) {
    showNotice('');
    if (SLOT_ACTIONS.includes(action)) { return openPanel(e, action); }
    if (action === 'cancel') { return cancel(e); }
    if (action === 'results') { return openReviewCb(e.event_id); }
    if ((action === 'start' || action === 'resume') && e.station_url) {
        window.location.href = e.station_url;
    }
}

function openPanel(e, mode) {
    panelMode = mode;
    requestKey = newRequestKey();
    chosenSessionId = null;
    paintPanel(e);
}

function closePanel() {
    panelMode = null;
    requestKey = null;
    chosenSessionId = null;
}

/** Карточка сеанса — крупная (дата, день недели, время, кабинет, места): «Новое» п. 1 в QA.md. */
function slotCard(s, mode) {
    const free = `осталось ${s.free} ${plural(s.free, 'место', 'места', 'мест')}`;
    const isCurrent = mode === 'change' && s.is_current;
    const disabled = !s.selectable || isCurrent;
    const sub = [`до ${s.time_end}`, s.room].filter(Boolean).map(esc).join(' · ');
    return `<button type="button" class="exam-slot${disabled ? ' off' : ''}" data-session="${s.session_id}" aria-pressed="false"${disabled ? ' disabled' : ''}>
        <span class="exam-slot-date">${esc(day(s.date))}</span>
        <span class="exam-slot-weekday">${esc(s.weekday)}</span>
        <span class="exam-slot-time">${esc(s.time_start)}</span>
        <span class="exam-slot-room">${sub}</span>
        <span class="exam-slot-free">${isCurrent ? 'ваша запись' : esc(s.selectable ? free : 'мест нет')}</span>
    </button>`;
}

function paintPanel(e) {
    const panel = rootEl.querySelector('#exPanel');
    if (!panel) { return; }
    if (!panelMode) { panel.hidden = true; panel.innerHTML = ''; return; }

    const slots = (e.sessions || []).map(s => slotCard(s, panelMode)).join('');

    panel.hidden = false;
    panel.innerHTML = `
        <div class="exam-panel-head">
            <div class="exam-panel-title">${panelMode === 'change' ? 'Выберите новый сеанс' : 'Выберите дату и время'}</div>
            <div class="exam-panel-sub">Смена и отмена записи — до начала выбранного сеанса.</div>
        </div>
        ${slots ? courseTabsShell('exSlots', { prevLabel: 'Предыдущие сеансы', nextLabel: 'Следующие сеансы' }) : '<div class="sc-empty">Сеансов пока нет.</div>'}
        <div class="exam-panel-actions">
            <button type="button" class="prof-btn prof-btn-primary" id="exConfirm" disabled>Подтвердить запись</button>
            <button type="button" class="prof-btn" id="exCancelPanel">Отмена выбора</button>
        </div>`;

    const strip = panel.querySelector('#exSlots');
    if (strip) {
        strip.innerHTML = slots;
        strip.querySelectorAll('.exam-slot:not(.off)').forEach(btn => btn.addEventListener('click', () => chooseSlot(strip, Number(btn.dataset.session))));
        syncCourseTabs(strip, { activeSelector: '.exam-slot.on', stepByCard: true });
    }
    panel.querySelector('#exCancelPanel').addEventListener('click', () => { closePanel(); paintPanel(e); showNotice(''); });
    panel.querySelector('#exConfirm').addEventListener('click', () => confirmSlot(e));
}

/** Выбор карточки без перерисовки панели: положение ленты не сбрасывается. */
function chooseSlot(strip, sessionId) {
    chosenSessionId = sessionId;
    strip.querySelectorAll('.exam-slot').forEach(btn => {
        const on = Number(btn.dataset.session) === sessionId;
        btn.classList.toggle('on', on);
        btn.setAttribute('aria-pressed', String(on));
    });
    const confirmBtn = rootEl.querySelector('#exConfirm');
    if (confirmBtn) { confirmBtn.disabled = false; }
}

async function confirmSlot(e) {
    if (!chosenSessionId) { return; }
    const slot = (e.sessions || []).find(s => s.session_id === chosenSessionId);
    if (panelMode === 'change') {
        const ok = await confirmDialog(`Сменить запись на ${day(slot?.date)}, ${slot?.time_start}?`, 'Сменить', 'Не менять');
        if (!ok) { return; }
    }
    const confirmBtn = rootEl.querySelector('#exConfirm');
    if (confirmBtn) { confirmBtn.disabled = true; }

    showNotice('');
    nextGeneration(); // идущая загрузка списка вернёт состояние до этой операции — отбросить
    const params = { session_id: chosenSessionId, request_key: requestKey };
    if (panelMode === 'change' && e.registration) { params.version = e.registration.version; }

    try {
        apply(await api(panelMode === 'change' ? 'change' : 'register', params));
    } catch (err) {
        await onFailure(err);
    }
}

async function cancel(e) {
    const ok = await confirmDialog('Отменить запись на экзамен? Место освободится.', 'Отменить запись', 'Не отменять');
    if (!ok) { return; }

    nextGeneration();
    const params = { event_id: e.event_id, request_key: newRequestKey() };
    if (e.registration) { params.version = e.registration.version; }

    try {
        apply(await api('cancel', params));
    } catch (err) {
        await onFailure(err);
    }
}

/**
 * Отказ правила: причина — над панелью, видна и при закрытой панели. Если показанное устарело (место
 * забрали, запись закрыли, вкладка отстала) — список перечитывается, а выбранный сеанс сбрасывается:
 * кнопка подтверждения не должна оставаться активной для уже недоступного выбора.
 */
async function onFailure(err) {
    const text = err.code === 'X-FULL' ? FULL_TEXT : (err.message || 'Не удалось выполнить действие.');

    if (REFRESH_CODES.includes(err.code)) {
        chosenSessionId = null;
        await refreshAfterFailure(text);
        return;
    }

    showNotice(text);
    const confirmBtn = rootEl.querySelector('#exConfirm');
    if (confirmBtn) { confirmBtn.disabled = !chosenSessionId; }
}

async function refreshAfterFailure(text) {
    const mine = nextGeneration();
    let data;
    try {
        data = await api('getExams', listParams());
    } catch {
        showNotice(text);
        return;
    }
    if (mine !== generation) { return; }

    const mode = panelMode;
    exams = data?.exams || exams;
    if (!exams.some(e => e.event_id === activeId)) { activeId = exams[0]?.event_id ?? null; }
    closePanel();
    paint();

    // Панель остаётся открытой, только если сервер всё ещё разрешает это действие; выбор — заново.
    const fresh = active();
    if (mode && fresh && (fresh.actions || []).includes(mode)) { openPanel(fresh, mode); }
    showNotice(text);
}
