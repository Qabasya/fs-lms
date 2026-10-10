/* ══════════════════════════════════════════════════════════════════════
   Раздел «Оплаты гостей» (этап 8.8.7): очередь «Оплачено, требуется помощь» и урегулированные заявки.
   Разметка — существующая: prof-seg (сегменты), pr-row (строки), gp-form (форма урегулирования). Сеть — createApi(window.fsProfile.exams).
   Что показывать и кому, решает сервер (проведения, доступные пользователю); клиент только рисует и отправляет выбор.
   Возврат денег здесь не выполняется: «возврат вне магазина» — отметка того, что сотрудник вернул деньги сам.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, emptyState } from '../utils.js';
import { ajaxErrorText } from '../../common/utils.js';
import { icoInbox, icoAlert } from '../../common/icons.js';
import { createApi } from '../api.js';
import { examConfig } from './exam-common.js';

const TABS = [
    { key: 'needs_help', label: 'Требуют помощи' },
    { key: 'resolved', label: 'Урегулированы' },
];

const KINDS = [
    { key: 'transferred', label: 'Перенести в другой сеанс' },
    { key: 'refunded_outside', label: 'Деньги возвращены вне магазина' },
    { key: 'other', label: 'Другое (только отметка)' },
];

const EMPTY = {
    needs_help: 'Оплат, требующих помощи, нет.',
    resolved: 'Урегулированных оплат пока нет.',
};

let root = null;
let api = null;
let tab = 'needs_help';
let items = [];
let openId = 0;

export function renderExamPayments(r) {
    root = r;
    const cfg = examConfig();
    if (!cfg) {
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Данные экзаменов недоступны', '', true);
        return;
    }
    api = createApi(cfg);
    openId = 0;
    load();
}

async function load() {
    try {
        items = (await api('getPaymentQueue', { tab })).items || [];
    } catch (err) {
        root.innerHTML = emptyState('prof-ktp', icoAlert(30), 'Не удалось загрузить оплаты', ajaxErrorText(err, 'Ошибка загрузки'), true);
        return;
    }
    render();
}

function render() {
    root.innerHTML = `
        <div class="exam-payments">
            <div class="prof-seg" role="tablist">${TABS.map(t =>
                `<button type="button" role="tab" class="${tab === t.key ? 'on' : ''}" data-tab="${t.key}">${esc(t.label)}</button>`).join('')}</div>
            ${items.length
                ? `<div class="pr-list">${items.map(rowHtml).join('')}</div>`
                : `<div class="exam-conduct-empty">${esc(EMPTY[tab])}</div>`}
        </div>`;
    wire();
}

function amountText(i) {
    return i.amount ? `${esc(i.amount)} ${esc(i.currency || '₽')}` : '—';
}

function rowHtml(i) {
    const head = `<div class="pr-info">
            <div class="pr-name">${esc(i.guest)}</div>
            <div class="pr-sub">${esc(i.event_title)} · сеанс ${esc(i.session)}${i.order_id ? ` · заказ №${i.order_id}` : ''} · ${amountText(i)}</div>
            ${'needs_help' === tab
                ? `<div class="pr-sub">${esc(i.reason)}${i.last_reconciled ? ` · сверка ${esc(i.last_reconciled.slice(0, 16))}` : ''}${i.responsible_name ? ` · ответственный: ${esc(i.responsible_name)}` : ''}</div>`
                : `<div class="pr-sub">${esc(i.kind_label)}${i.new_session ? ` → ${esc(i.new_session)}` : ''}${i.refund ? ` · возврат ${esc(i.refund)} ₽` : ''} · ${esc(i.resolved_by)} ${esc((i.resolved_at || '').slice(0, 16))}</div>
                   <div class="pr-sub">Причина: ${esc(i.reason)}</div>`}
        </div>`;
    const action = 'needs_help' === tab ? `<button type="button" class="prof-btn prof-btn-sm" data-resolve="${i.application_id}">Урегулировать</button>` : '';
    const form = 'needs_help' === tab && openId === i.application_id ? formHtml(i) : '';
    return `<div class="pr-row exam-payment-row" data-app="${i.application_id}">${head}${action}</div>${form}`;
}

function formHtml(i) {
    const targets = i.targets || [];
    return `<div class="gp-form gp-exam exam-payment-form" data-form="${i.application_id}">
        <label class="gp-field"><span>Как урегулировать</span><select data-f="kind">${KINDS.map(k => `<option value="${k.key}">${esc(k.label)}</option>`).join('')}</select></label>
        <label class="gp-field" data-row="session"><span>Сеанс</span>${targets.length
            ? `<select data-f="session">${targets.map(t => `<option value="${t.id}">${esc(t.label)} · свободно ${t.free}</option>`).join('')}</select>`
            : '<b class="gp-fixed">В проведении нет сеанса со свободным местом</b>'}</label>
        <label class="gp-field" data-row="amount" hidden><span>Сумма возврата, ₽</span><input type="text" inputmode="decimal" data-f="amount" value="${esc(i.amount || '')}"></label>
        <label class="gp-field gp-field--top"><span>Причина</span><textarea data-f="reason" rows="3" maxlength="500"></textarea></label>
        <div class="gp-warn" data-row="refund-note" hidden>Деньги вы возвращаете сами (в магазине или у банка). LMS возврат не выполняет и заказ не меняет.</div>
        <div class="gp-error" data-f="error" role="alert" hidden></div>
        <div class="gp-row">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-f="submit">Урегулировать</button>
            <button type="button" class="prof-btn prof-btn-sm" data-f="cancel">Закрыть</button>
        </div>
    </div>`;
}

function wire() {
    root.querySelectorAll('[data-tab]').forEach(btn => btn.addEventListener('click', () => { tab = btn.dataset.tab; openId = 0; load(); }));
    root.querySelectorAll('[data-resolve]').forEach(btn => btn.addEventListener('click', () => {
        openId = openId === Number(btn.dataset.resolve) ? 0 : Number(btn.dataset.resolve);
        render();
    }));
    root.querySelectorAll('[data-form]').forEach(form => wireForm(form, Number(form.dataset.form)));
}

function wireForm(form, applicationId) {
    const $ = sel => form.querySelector(sel);
    const sync = () => {
        const kind = $('[data-f="kind"]').value;
        $('[data-row="session"]').hidden = 'transferred' !== kind;
        $('[data-row="amount"]').hidden = 'refunded_outside' !== kind;
        $('[data-row="refund-note"]').hidden = 'refunded_outside' !== kind;
    };
    $('[data-f="kind"]').addEventListener('change', sync);
    sync();
    $('[data-f="cancel"]').addEventListener('click', () => { openId = 0; render(); });
    $('[data-f="submit"]').addEventListener('click', async () => {
        const kind = $('[data-f="kind"]').value;
        const error = $('[data-f="error"]');
        error.hidden = true;
        try {
            items = (await api('resolvePayment', {
                application_id: applicationId,
                kind,
                session_id: 'transferred' === kind ? ($('[data-f="session"]')?.value || '') : '',
                amount: 'refunded_outside' === kind ? $('[data-f="amount"]').value : '',
                reason: $('[data-f="reason"]').value,
            })).items || [];
            openId = 0;
            toast('Заявка урегулирована');
            render();
        } catch (err) {
            error.hidden = false;
            error.textContent = ajaxErrorText(err, 'Не удалось урегулировать заявку');
        }
    });
}
