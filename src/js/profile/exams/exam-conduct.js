/* ══════════════════════════════════════════════════════════════════════
   Экран «Проведение экзамена» (этап 8.1–8.2): доска сеанса — кто записан, кто начал, кто сдал, кто не явился, сколько осталось.
   Разметка — существующая: prof-seg (переключатель сеансов), prof-stat-tile (плитки), pr-row (участники), prof-state-pill, ctx-меню,
   поповер prof-grade-pop + gp-form (формы действий). Сеть — createApi(window.fsProfile.exams).

   Автообновление — повтор запроса раз в 30 с, пока экран на странице и вкладка видима; между запросами «остаток»
   уменьшается таймером от `seconds_left`. Перезагрузка страницы ничего не продлевает: дедлайн хранится на сервере.
   Права и список действий строки решает сервер (`actions`); клиент только рисует.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState, openCtxMenu, openGradePopPositioned, closeGradePop, plural, fmtDate } from '../utils.js';
import { ajaxErrorText } from '../../common/utils.js';
import { icoCalendarBoard, icoAlert } from '../../common/icons.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { createApi } from '../api.js';
import { examConfig, approvalBlockReason, approvableRows, approveButtonLabel, approvalItems, approvalSummaryText, skippedListHtml, exportButtonsHtml, wireExportButtons } from './exam-common.js';
import { openMoveSessionForm, openCancelSessionForm } from './exam-session-actions.js';

const REFRESH_MS = 30000;
const TICK_MS = 1000;
const MIN_EXTENSION = 1;
const MAX_EXTENSION = 120;
const DEFAULT_EXTENSION = 10;

const ACTION_LABELS = {
    cancel: 'Отменить запись',
    transfer: 'Назначить другой сеанс',
    extend: 'Продлить',
    arrival: 'Отметить приход',
    open_work: 'Открыть работу',
    admit: 'Допустить к экзамену',
    entry_link: 'Выдать ссылку на вход',
    result_link: 'Скопировать ссылку результата',
    revoke_result_link: 'Отозвать ссылку результата',
    anonymize: 'Удалить данные гостя',
    mark_entry_passed: 'Отметить: ссылка входа передана',
    mark_result_passed: 'Отметить: ссылка результата передана',
};

/** Оплата гостя → пилюля `prof-state-*` (оплачено — активная, ожидание — «скоро», остальное — нейтральная). */
const PAYMENT_PILL = { paid: 'prof-state-now', pending: 'prof-state-soon' };

const REGISTRATION_LABEL = { confirmed: 'Запись подтверждена', cancelled: 'Запись отменена', missed: 'Неявка' };

const PROGRESS_PILL = {
    not_started: 'prof-state-soon',
    in_progress: 'prof-state-now',
    submitted: 'prof-state-done',
    missed: 'prof-state-done',
};

let requestedSessionId = 0;
let handlers = {};
let root = null;
let api = null;
let board = null;
let sessionId = 0;
let fetchedAt = 0;
let selecting = false;
let selected = new Set();
let lastSkipped = [];
let refreshTimer = 0;
let tickTimer = 0;

/** Сеанс, который попросили открыть (с «Главной»); экран читает его при отрисовке. */
export function openExamConductFor(id) {
    requestedSessionId = Number(id) || 0;
}

export function requestedConductSession() {
    return requestedSessionId;
}

export function renderExamConduct(r, h = {}) {
    stopTimers();
    root = r;
    handlers = h;
    const cfg = examConfig();
    if (!cfg) {
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Данные экзаменов недоступны', '', true);
        return;
    }
    api = createApi(cfg);
    sessionId = requestedSessionId;
    requestedSessionId = 0;
    resetSelection();
    root.innerHTML = '<div class="exam-conduct-loading">Загрузка…</div>';
    load();
    startTimers();
}

/* ── Данные ───────────────────────────────────────────────────────────── */
async function load() {
    try {
        const data = await api('getConduct', sessionId ? { session_id: sessionId } : {});
        board = data.board || null;
    } catch (err) {
        board = null;
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить сеанс', ajaxErrorText(err, 'Ошибка загрузки'), true);
        return;
    }
    if (board) { sessionId = board.session.id; }
    fetchedAt = Date.now();
    render();
}

/** Ответ действия — свежая доска: перерисовываем без повторного запроса. */
function applyBoard(data) {
    board = data.board;
    sessionId = board.session.id;
    fetchedAt = Date.now();
    render();
}

async function act(action, params, okText) {
    try {
        applyBoard(await api(action, Object.assign({ session_id: sessionId }, params)));
        if (okText) { toast(okText); }
        return true;
    } catch (err) {
        toast(ajaxErrorText(err, 'Не удалось выполнить действие'), 'error');
        return false;
    }
}

function resetSelection() {
    selecting = false;
    selected = new Set();
    lastSkipped = [];
}

/* ── Таймеры ──────────────────────────────────────────────────────────── */
function stopTimers() {
    window.clearInterval(refreshTimer);
    window.clearInterval(tickTimer);
    refreshTimer = 0;
    tickTimer = 0;
}

function startTimers() {
    refreshTimer = window.setInterval(() => {
        if (!root || !root.isConnected) { stopTimers(); return; }
        if (document.hidden || !board) { return; }
        load();
    }, REFRESH_MS);
    tickTimer = window.setInterval(() => {
        if (!root || !root.isConnected) { stopTimers(); return; }
        tick();
    }, TICK_MS);
}

/** «Остаток» идёт от значения сервера: клиент часов не доверяет и от своего времени дедлайн не считает. */
function tick() {
    const elapsed = Math.floor((Date.now() - fetchedAt) / 1000);
    root.querySelectorAll('[data-left]').forEach(el => {
        el.textContent = fmtLeft(Math.max(0, Number(el.dataset.left) - elapsed));
    });
}

export function fmtLeft(total) {
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = n => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}

/* ── Отрисовка ────────────────────────────────────────────────────────── */
function render() {
    if (!board) {
        root.innerHTML = emptyState('prof-ktp', icoCalendarBoard(34), 'Экзамены не назначены.', 'Назначьте экзамен в разделе «Назначить экзамен».');
        return;
    }
    const s = board.session;
    root.innerHTML = `
        <div class="exam-conduct">
            ${sessionSwitcher()}
            <div class="exam-conduct-head">
                <div class="exam-conduct-titlebar">
                    <h2 class="exam-conduct-title">${esc(s.event_title)}</h2>
                    ${sessionMenuAllowed(s) ? '<button type="button" class="prof-btn prof-btn-sm prof-btn-ghost" data-session-menu aria-label="Действия с сеансом">⋯</button>' : ''}
                </div>
                <div class="exam-conduct-sub">${esc(fmtDate(s.date))} · ${esc(s.time_start)}–${esc(s.time_end)}${s.room_name ? ` · ${esc(s.room_name)}` : ''} · занято ${s.occupied} из ${s.capacity}</div>
            </div>
            ${tilesHtml(board.tiles)}
            ${board.warnings.map(w => `<div class="sc-notice exam-notice">${esc(w)}</div>`).join('')}
            ${toolbar()}
            ${skippedListHtml(lastSkipped, id => board.rows.find(r => r.attempt_id === id)?.name)}
            ${board.rows.length
                ? `<div class="pr-list">${board.rows.map(rowHtml).join('')}</div>`
                : (board.holds || []).length ? '' : '<div class="exam-conduct-empty">На этот сеанс пока никто не записан.</div>'}
            ${(board.holds || []).length ? `<div class="exam-conduct-subtitle">Ожидают оплаты</div><div class="pr-list">${board.holds.map(holdRowHtml).join('')}</div>` : ''}
        </div>`;
    wire();
}

/** Перенос и отмена — пока сеанс открыт и ни одна попытка не начата. */
function sessionMenuAllowed(s) {
    return 'open' === s.status && !s.is_locked;
}

/** Панель действий над списком: утверждение работ и выгрузка (CSV, печать — только при обоих экспортных правах). */
function toolbar() {
    const approval = approvalToolbar();
    const exports = board.rows.length ? exportButtonsHtml() : '';
    const guest = guestButtonHtml();
    if (!approval && !exports && !guest) { return ''; }
    return `<div class="exam-conduct-toolbar">${guest}${approval}${exports}</div>`;
}

/** «Добавить гостя» — только с правом на гостей, пока сеанс открыт и есть источник; окончательно решает сервер. */
function guestButtonHtml() {
    const cfg = examConfig();
    if (!cfg || !cfg.canManageGuests || 'open' !== board.session.status || !(board.sources || []).length) { return ''; }
    return '<button type="button" class="prof-btn prof-btn-sm" data-add-guest>Добавить гостя</button>';
}

/** Панель массового утверждения: кнопка входа в режим выбора или «Отмена» и кнопка подтверждения. */
function approvalToolbar() {
    if (!approvableRows(board.rows).length && !selecting) { return ''; }
    if (!selecting) {
        return '<button type="button" class="prof-btn prof-btn-sm" data-approve="start">Утвердить работы</button>';
    }
    return `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-approve="confirm">${esc(approveButtonLabel(selected.size))}</button>
        <button type="button" class="prof-btn prof-btn-sm" data-approve="cancel">Отмена</button>`;
}

function sessionSwitcher() {
    if (board.sessions.length < 2) { return ''; }
    return `<div class="prof-seg exam-conduct-sessions" role="tablist">${board.sessions.map(x =>
        `<button type="button" role="tab" class="${x.id === sessionId ? 'on' : ''}" data-session="${x.id}">${esc(fmtDate(x.date))} · ${esc(x.time_start)}</button>`
    ).join('')}</div>`;
}

function tilesHtml(t) {
    const tile = (label, value) => `<div class="prof-stat-tile"><div class="st-top">${esc(label)}</div><div class="st-val">${value}</div></div>`;
    return `<div class="prof-stat-tiles exam-conduct-tiles">
        ${tile('Записано', t.registered)}${tile('Начали', t.started)}${tile('Сдали', t.submitted)}${tile('Не явились', t.missed)}${tile('Ожидают проверки', t.pending)}
    </div>`;
}

function rowHtml(r) {
    const left = null !== r.seconds_left
        ? `<span class="exam-conduct-left" data-left="${r.seconds_left}">${fmtLeft(r.seconds_left)}</span>`
        : '';
    const passed = [r.entry_link_passed, r.result_link_passed].filter(Boolean).map(esc).join(' · ');
    const arrived = r.arrived_at ? `<span class="exam-conduct-arrived" title="Приход отмечен">приход ${esc(r.arrived_at.slice(11, 16))}</span>` : '';
    const cancelled = 'cancelled' === r.registration_status;
    const pill = cancelled
        ? '<span class="prof-state-pill prof-state-done">Запись отменена</span>'
        : `<span class="prof-state-pill ${PROGRESS_PILL[r.progress] || 'prof-state-done'}">${esc(r.progress_label)}</span>`;
    const result = 'none' === r.result_status ? '' : `<span class="prof-state-pill prof-state-soon">${esc(r.result_status_label)}</span>`;
    const score = r.score ? `<span class="exam-conduct-score">${scoreText(r.score)}</span>` : '';

    return `<div class="pr-row exam-conduct-row${cancelled ? ' is-off' : ''}" data-reg="${r.registration_id}">
        ${selecting ? checkboxHtml(r) : ''}
        <div class="pr-info">
            <div class="pr-name">${esc(r.name)}</div>
            <div class="pr-sub">${esc(r.audience_label)}${r.source ? ` · ${esc(r.source)}` : ''} ${arrived}</div>
            ${'guest' === r.audience ? guestPillsHtml(r) : ''}
            ${passed ? `<div class="pr-sub">${passed}</div>` : ''}
        </div>
        ${score}${left}${'guest' === r.audience ? '' : pill}${result}
        ${r.actions.length ? '<button type="button" class="prof-btn prof-btn-sm prof-btn-ghost" data-menu aria-label="Действия">⋯</button>' : ''}
    </div>`;
}

/** У гостя четыре независимых состояния, а не одна «галочка»: оплата, запись, допуск и попытка — отдельными пилюлями. */
function guestPillsHtml(r) {
    const pay = r.payment
        ? `<span class="prof-state-pill ${PAYMENT_PILL[r.payment.state] || 'prof-state-done'}">Оплата: ${esc(r.payment.label)}</span>`
        : '<span class="prof-state-pill prof-state-done">Оплата: —</span>';
    const registration = `<span class="prof-state-pill prof-state-done">${esc(REGISTRATION_LABEL[r.registration_status] || r.registration_status)}</span>`;
    const admission = `<span class="prof-state-pill ${r.admitted_at ? 'prof-state-now' : 'prof-state-soon'}">${r.admitted_at ? 'Допущен' : 'Не допущен'}</span>`;
    const progress = `<span class="prof-state-pill ${PROGRESS_PILL[r.progress] || 'prof-state-done'}">Попытка: ${esc(r.progress_label)}</span>`;

    return `<div class="exam-guest-pills">${pay}${registration}${admission}${progress}</div>`;
}

/** Гости с бронью без записи: «Место удерживается до …» и ссылка на оплату (11a.7.7). Не строки доски: записи и попытки у них ещё нет. */
function holdRowHtml(h) {
    return `<div class="pr-row exam-conduct-row exam-hold-row" data-app="${h.application_id}">
        <div class="pr-info">
            <div class="pr-name">${esc(h.name)}</div>
            <div class="pr-sub">Гость${h.on_site ? ' · добавлен на месте' : ''} · Место удерживается до ${esc(h.hold_until)}</div>
        </div>
        <span class="prof-state-pill prof-state-soon">Ожидает оплаты</span>
        ${(h.actions || []).includes('copy_pay_link') && examConfig()?.canManageGuests ? '<button type="button" class="prof-btn prof-btn-sm" data-copy-pay>Скопировать ссылку на оплату</button>' : ''}
    </div>`;
}

/** Чекбокс режима выбора: у неготовых работ неактивен, причина — в подсказке. */
function checkboxHtml(r) {
    const reason = approvalBlockReason(r);
    return `<input type="checkbox" class="exam-conduct-check" data-attempt="${r.attempt_id || ''}" aria-label="Выбрать работу"
        ${reason ? `disabled title="${esc(reason)}"` : (selected.has(r.attempt_id) ? 'checked' : '')}>`;
}

/** «37 / 56 · 84» для ЕГЭ, «15 / 19 · оценка 4» для ОГЭ; при незавершённой проверке вторичного балла нет. */
export function scoreText(sc) {
    const base = `${sc.primary} / ${sc.primary_max}`;
    if (sc.pending) { return `${esc(base)} · проверка не завершена`; }
    if (null !== sc.secondary) { return `${esc(base)} · ${sc.secondary}`; }
    if (null !== sc.grade) { return `${esc(base)} · оценка ${sc.grade}`; }
    return esc(base);
}

/** Массовое утверждение: отправляет выбранные (или все готовые), показывает итог и закрывает режим выбора. */
async function approveSelected() {
    const items = approvalItems(board.rows, selected);
    if (!items.length) { toast('Нет работ, готовых к утверждению', 'error'); return; }
    try {
        const result = await api('approveAttempts', { session_id: sessionId, items });
        toast(approvalSummaryText(result));
        lastSkipped = result.skipped || [];
        selecting = false;
        selected = new Set();
        applyBoard(result);
    } catch (err) {
        toast(ajaxErrorText(err, 'Не удалось утвердить работы'), 'error');
    }
}

/* ── Действия ─────────────────────────────────────────────────────────── */
function wire() {
    root.querySelectorAll('[data-session]').forEach(btn => btn.addEventListener('click', () => {
        sessionId = Number(btn.dataset.session);
        resetSelection();
        load();
    }));
    wireExportButtons(root, api, () => ({ session_id: sessionId }));
    root.querySelector('[data-approve="start"]')?.addEventListener('click', () => { selecting = true; selected = new Set(); render(); });
    root.querySelector('[data-approve="cancel"]')?.addEventListener('click', () => { selecting = false; selected = new Set(); render(); });
    root.querySelector('[data-approve="confirm"]')?.addEventListener('click', approveSelected);
    root.querySelectorAll('.exam-conduct-check').forEach(box => box.addEventListener('change', () => {
        const id = Number(box.dataset.attempt);
        if (box.checked) { selected.add(id); } else { selected.delete(id); }
        const confirm = root.querySelector('[data-approve="confirm"]');
        if (confirm) { confirm.textContent = approveButtonLabel(selected.size); }
    }));
    root.querySelectorAll('.exam-hold-row [data-copy-pay]').forEach(btn => btn.addEventListener('click', async () => {
        const applicationId = Number(btn.closest('.exam-hold-row').dataset.app);
        try {
            const r = await api('issuePayLink', { application_id: applicationId });
            try {
                await navigator.clipboard.writeText(r.pay_url);
                toast('Ссылка на оплату скопирована. Прежняя перестала работать.');
            } catch {
                const pop = document.getElementById('profGradePop');
                if (pop) { showLinkPop(pop, 'Ссылка на оплату', 'Передайте гостю ссылку — она показана один раз.', 'Ссылка на оплату', r.pay_url); openGradePopPositioned(pop, btn); }
            }
        } catch (err) {
            toast(ajaxErrorText(err, 'Не удалось выдать ссылку'), 'error');
        }
    }));
    root.querySelector('[data-add-guest]')?.addEventListener('click', e => openGuestForm(e.currentTarget));
    root.querySelector('[data-session-menu]')?.addEventListener('click', e => {
        const s = Object.assign({ date: board.session.date, time_start: board.session.time_start }, board.session);
        const ctx = { api, anchor: e.currentTarget, session: s, rooms: board.rooms, onDone: () => { load(); } };
        openCtxMenu(e.currentTarget, [{ v: 'move', label: 'Перенести сеанс' }, { v: 'cancel', label: 'Отменить сеанс' }],
            v => ('move' === v ? openMoveSessionForm(ctx) : openCancelSessionForm(ctx)));
    });
    root.querySelectorAll('.exam-conduct-row [data-menu]').forEach(btn => btn.addEventListener('click', () => {
        const row = board.rows.find(r => r.registration_id === Number(btn.closest('.exam-conduct-row').dataset.reg));
        if (!row) { return; }
        const items = row.actions.filter(a => 'anonymize' !== a || examConfig()?.canAnonymizeGuests).map(a => ({ v: a, label: actionLabel(a, row) }));
        openCtxMenu(btn, items, v => runAction(v, row, btn));
    }));
}

function actionLabel(action, row) {
    if ('arrival' === action && row.arrived_at) { return 'Снять отметку прихода'; }
    if ('admit' === action && row.admitted_at) { return 'Снять допуск'; }
    if ('entry_link' === action && row.entry_link_issued) { return 'Перевыпустить ссылку на вход'; }
    if ('result_link' === action && row.result_link_issued) { return 'Скопировать ссылку результата (выдать новую)'; }
    return ACTION_LABELS[action];
}

/** Ссылка гостя (вход или результат) отдаётся один раз: копируется в буфер; при сбое буфера — показывается в окне для ручного копирования. */
async function issueGuestLink(row, anchor, { apiKey, issued, title, label }) {
    if (issued && !await confirmDialog('Прежняя ссылка перестанет работать.', 'Перевыпустить', 'Отмена')) { return; }
    try {
        const r = await api(apiKey, { participation_id: row.participation_id });
        try {
            await navigator.clipboard.writeText(r.url);
            toast('Ссылка скопирована. Передайте её участнику.');
        } catch {
            const pop = document.getElementById('profGradePop');
            if (pop) {
                showLinkPop(pop, title, 'Скопируйте ссылку и передайте её участнику.', label, r.url);
                openGradePopPositioned(pop, anchor);
            }
        }
        load();
    } catch (err) {
        toast(ajaxErrorText(err, 'Не удалось выдать ссылку'), 'error');
    }
}

function runAction(action, row, anchor) {
    switch (action) {
        case 'cancel': openReasonForm(anchor, { title: 'Отменить запись', submit: 'Отменить запись', confirm: `Отменить запись: ${row.name}?`, run: reason => act('cancelRegistration', { registration_id: row.registration_id, reason }, 'Запись отменена') }); break;
        case 'transfer': openTransferForm(anchor, row); break;
        case 'extend': openExtendForm(anchor, row); break;
        case 'arrival': act('markArrival', { registration_id: row.registration_id, arrived: row.arrived_at ? 0 : 1 }); break;
        case 'admit': act('admitGuest', { participation_id: row.participation_id, admitted: row.admitted_at ? 0 : 1 }, row.admitted_at ? 'Допуск снят' : 'Участник допущен'); break;
        case 'entry_link': issueGuestLink(row, anchor, { apiKey: 'issueEntryLink', issued: row.entry_link_issued, title: 'Ссылка на вход', label: 'Ссылка на вход' }); break;
        case 'result_link': issueGuestLink(row, anchor, { apiKey: 'issueResultLink', issued: row.result_link_issued, title: 'Ссылка результата', label: 'Ссылка результата' }); break;
        case 'anonymize': openReasonForm(anchor, {
            title: 'Удалить данные гостя', submit: 'Удалить',
            confirm: `Удалить данные гостя ${row.name}? ФИО и контакты будут стёрты, ссылки перестанут работать. Отменить нельзя.`,
            run: reason => act('anonymizeGuest', { participant_id: row.participant_id, reason }, 'Данные гостя удалены'),
        }); break;
        case 'mark_entry_passed': act('markLinkPassed', { participation_id: row.participation_id, purpose: 'entry' }, 'Отмечено'); break;
        case 'mark_result_passed': act('markLinkPassed', { participation_id: row.participation_id, purpose: 'result' }, 'Отмечено'); break;
        case 'revoke_result_link': act('revokeResultLink', { participation_id: row.participation_id }, 'Ссылка результата отозвана'); break;
        case 'open_work': handlers.openWorkReview?.('attempt', row.attempt_id); break;
        default: break;
    }
}

/** Поповер с полем причины; `fields` — HTML дополнительных полей перед причиной. */
function openPop(anchor, { title, fieldsHtml = '', submit, onSubmit }) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return; }
    pop.innerHTML = `<div class="gp-form gp-exam">
        <div class="gp-title">${esc(title)}</div>
        ${fieldsHtml}
        <label class="gp-field gp-field--top"><span>Причина</span><textarea id="ecReason" rows="3" maxlength="500"></textarea></label>
        <div class="gp-error" id="ecError" role="alert" hidden></div>
        <div class="gp-row">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-ec="ok">${esc(submit)}</button>
            <button type="button" class="prof-btn prof-btn-sm" data-ec="cancel">Закрыть</button>
        </div>
    </div>`;
    const $ = sel => pop.querySelector(sel);
    const showError = text => { const box = $('#ecError'); box.hidden = false; box.textContent = text; };
    $('[data-ec="cancel"]').addEventListener('click', closeGradePop);
    $('[data-ec="ok"]').addEventListener('click', async () => {
        const reason = $('#ecReason').value.trim();
        if (!reason) { showError('Укажите причину.'); return; }
        const err = await onSubmit(reason, $);
        if (false === err) { return; } // отказ в подтверждении: форма остаётся открытой
        if (err) { showError(err); } else { closeGradePop(); }
    });
    openGradePopPositioned(pop, anchor);
}

function openReasonForm(anchor, o) {
    openPop(anchor, {
        title: o.title,
        submit: o.submit,
        onSubmit: async reason => {
            if (!await confirmDialog(o.confirm, o.submit, 'Не отменять')) { return false; }
            await o.run(reason);
            return '';
        },
    });
}

function openTransferForm(anchor, row) {
    const options = board.sessions.filter(x => x.id !== sessionId && x.free > 0 && 'open' === x.status);
    openPop(anchor, {
        title: 'Назначить другой сеанс',
        submit: 'Перенести',
        fieldsHtml: options.length
            ? `<label class="gp-field"><span>Сеанс</span><select id="ecTarget">${options.map(x =>
                `<option value="${x.id}">${esc(fmtDate(x.date))} · ${esc(x.time_start)} · ${x.free} ${plural(x.free, 'место', 'места', 'мест')}</option>`).join('')}</select></label>`
            : '<div class="gp-warn">Нет другого сеанса со свободным местом.</div>',
        onSubmit: async (reason, $) => {
            const target = $('#ecTarget');
            if (!target) { return 'Нет другого сеанса со свободным местом.'; }
            await act('transferRegistration', { registration_id: row.registration_id, target_session_id: target.value, reason }, 'Запись перенесена');
            return '';
        },
    });
}

function openExtendForm(anchor, row) {
    openPop(anchor, {
        title: 'Продлить попытку',
        submit: 'Продлить',
        fieldsHtml: `<label class="gp-field"><span>Минут</span><input type="number" id="ecMinutes" min="${MIN_EXTENSION}" max="${MAX_EXTENSION}" value="${DEFAULT_EXTENSION}"></label>`,
        onSubmit: async (reason, $) => {
            const minutes = Number($('#ecMinutes').value);
            if (!Number.isInteger(minutes) || minutes < MIN_EXTENSION || minutes > MAX_EXTENSION) {
                return `Продлить можно на срок от ${MIN_EXTENSION} до ${MAX_EXTENSION} минут.`;
            }
            const ok = await act('extendAttempt', { attempt_id: row.attempt_id, minutes, reason }, 'Попытка продлена');
            return ok ? '' : 'Не удалось продлить попытку.';
        },
    });
}

/* ── Гость на месте (11a.7) ───────────────────────────────────────────── */

/** Сначала форма, потом (при похожем госте) подтверждение, в конце — ссылка на оплату один раз. Оплата — только по ссылке. */
function openGuestForm(anchor) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return; }
    const requestKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random();
    const field = (name, label, required = false) => `<label class="gp-field"><span>${label}</span><input type="text" name="${name}" maxlength="100"${required ? ' required' : ''}></label>`;
    pop.innerHTML = `<form class="gp-form gp-exam" id="ecGuestForm">
        <div class="gp-title">Добавить гостя</div>
        <label class="gp-field"><span>Источник</span><select name="source_id">${board.sources.map(x => `<option value="${x.id}">${esc(x.label)}</option>`).join('')}</select></label>
        ${field('last_name', 'Фамилия', true)}${field('first_name', 'Имя', true)}${field('middle_name', 'Отчество')}${field('phone', 'Телефон', true)}${field('messenger', 'Мессенджер')}
        <label class="gp-field"><span><input type="checkbox" name="consents[]" value="pd_processing"> Согласие на обработку персональных данных получено</span></label>
        <div class="gp-error" id="ecGuestError" role="alert" hidden></div>
        <div class="gp-row">
            <button type="submit" class="prof-btn prof-btn-sm prof-btn-primary">Добавить</button>
            <button type="button" class="prof-btn prof-btn-sm" data-ec="cancel">Закрыть</button>
        </div>
    </form>`;
    const form = pop.querySelector('#ecGuestForm');
    const errorBox = pop.querySelector('#ecGuestError');
    const showError = text => { errorBox.hidden = false; errorBox.textContent = text; };
    pop.querySelector('[data-ec="cancel"]').addEventListener('click', closeGradePop);
    let confirmed = 0;
    form.addEventListener('submit', async ev => {
        ev.preventDefault();
        errorBox.hidden = true;
        const data = new FormData(form);
        const params = { session_id: sessionId, request_key: requestKey, confirmed };
        data.forEach((v, k) => { if ('consents[]' !== k) { params[k] = v; } });
        // Транспорт — URLSearchParams из объекта: массив уходит одним ключом `consents[]`.
        if (data.getAll('consents[]').length) { params['consents[]'] = 'pd_processing'; }
        try {
            const r = await api('addGuest', params);
            if ('needs_confirmation' === r.status) {
                confirmed = 1;
                const names = (r.candidates || []).map(c => esc(c.name || 'похожий гость')).join(', ');
                showError(`Уже есть похожий гость (${names}). Нажмите «Добавить» ещё раз, если это другой человек.`);
                return;
            }
            showGuestLink(pop, r);
            load();
        } catch (err) {
            showError(ajaxErrorText(err, 'Не удалось добавить гостя'));
        }
    });
    openGradePopPositioned(pop, anchor);
}

/** Ссылка на оплату показывается один раз; потерянную можно выпустить заново из строки гостя. */
function showGuestLink(pop, r) {
    const note = `Место удерживается ${r.seconds_left ? `ещё ${Math.ceil(r.seconds_left / 60)} мин` : 'недолго'}. Передайте ссылку на оплату — она показана один раз.`;
    showLinkPop(pop, 'Гость добавлен', note, 'Ссылка на оплату', r.pay_url);
}

/** Поповер со ссылкой, выделенной для копирования, и кнопкой «Скопировать». */
function showLinkPop(pop, title, note, label, url) {
    pop.innerHTML = `<div class="gp-form gp-exam">
        <div class="gp-title">${esc(title)}</div>
        <div class="gp-warn">${esc(note)}</div>
        <label class="gp-field gp-field--top"><span>${esc(label)}</span><input type="text" readonly value="${esc(url)}" id="ecLinkUrl"></label>
        <div class="gp-row">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-ec="copy">Скопировать ссылку</button>
            <button type="button" class="prof-btn prof-btn-sm" data-ec="cancel">Закрыть</button>
        </div>
    </div>`;
    const input = pop.querySelector('#ecLinkUrl');
    input.select();
    pop.querySelector('[data-ec="cancel"]').addEventListener('click', closeGradePop);
    pop.querySelector('[data-ec="copy"]').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(url); toast('Ссылка скопирована'); } catch { input.select(); }
    });
}
