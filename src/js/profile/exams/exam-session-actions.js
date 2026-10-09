/* ══════════════════════════════════════════════════════════════════════
   Перенос и отмена сеанса с участниками (этап 8.3.6). Поповер `prof-grade-pop` + `gp-form`, как остальные формы экзаменов.
   Общий для экрана «Назначить экзамен» (форма сеанса) и «Проведение экзамена»: одно место, два вызова.

   Причина обязательна и уходит участникам в уведомлении. Сервер проверяет всё сам (сеанс не начат, версия, кабинет):
   клиент показывает ошибку внутри формы, введённое не теряется.

   Вызов: openMoveSessionForm({ api, anchor, session, rooms, onDone }), openCancelSessionForm({ api, anchor, session, onDone }).
   `session` — { id, date, time_start, room_id, version }, `rooms` — [{ id, name, seats }].
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openGradePopPositioned, closeGradePop, plural } from '../utils.js';
import { ajaxErrorText } from '../../common/utils.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';

const STALE_CODE = 'X-STALE';
const STALE_TEXT = 'Сеанс изменили в другой вкладке. Обновите страницу.';

function roomOptions(rooms, selectedId) {
    return rooms.map(r =>
        `<option value="${r.id}" ${String(r.id) === String(selectedId) ? 'selected' : ''}>${esc(r.name)} · ${r.seats} ${plural(r.seats, 'место', 'места', 'мест')}</option>`
    ).join('');
}

/** Каркас формы: заголовок, поля, причина, ошибка, кнопки. Возвращает { pop, $, showError, setBusy } или null без поповера. */
function openForm(o, { title, fieldsHtml, submit, close }) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return null; }

    pop.innerHTML = `<div class="gp-form gp-indi gp-exam">
        <div class="gp-title">${esc(title)}</div>
        ${fieldsHtml}
        <label class="gp-field gp-field--top"><span>Причина</span><textarea id="esReason" rows="3" maxlength="500" placeholder="Что сообщить участникам"></textarea></label>
        <div class="gp-error" id="esActionError" role="alert" hidden></div>
        <div class="gp-row">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-esa="ok">${esc(submit)}</button>
            <button type="button" class="prof-btn prof-btn-sm" data-esa="close">${esc(close)}</button>
        </div>
    </div>`;

    const $ = sel => pop.querySelector(sel);
    const showError = (text, withRefresh = false) => {
        const box = $('#esActionError');
        box.hidden = false;
        box.innerHTML = esc(text) + (withRefresh ? ' <button type="button" class="prof-btn prof-btn-sm" data-esa="refresh">Обновить</button>' : '');
        box.querySelector('[data-esa="refresh"]')?.addEventListener('click', () => { closeGradePop(); o.onDone(); });
    };
    const setBusy = busy => pop.querySelectorAll('[data-esa]').forEach(btn => { btn.disabled = busy; });
    $('[data-esa="close"]').addEventListener('click', closeGradePop);

    return { pop, $, showError, setBusy };
}

function reportFailure(form, err) {
    form.setBusy(false);
    form.showError(STALE_CODE === err.code ? STALE_TEXT : ajaxErrorText(err, 'Не удалось выполнить действие'), STALE_CODE === err.code);
}

export function openMoveSessionForm(o) {
    const { api, session, rooms = [] } = o;
    const form = openForm(o, {
        title: 'Перенести сеанс',
        submit: 'Перенести',
        close: 'Закрыть',
        fieldsHtml: `
            <label class="gp-field"><span>Дата</span><input type="date" id="esMoveDate" value="${esc(session.date)}"></label>
            <label class="gp-field"><span>Время начала</span><input type="time" id="esMoveTime" value="${esc(session.time_start)}"></label>
            <label class="gp-field"><span>Кабинет</span><select id="esMoveRoom">${roomOptions(rooms, session.room_id)}</select></label>`,
    });
    if (!form) { return; }

    form.$('[data-esa="ok"]').addEventListener('click', async () => {
        const reason = form.$('#esReason').value.trim();
        const params = {
            session_id: session.id,
            date: form.$('#esMoveDate').value,
            time: form.$('#esMoveTime').value,
            room_id: form.$('#esMoveRoom').value,
            reason,
            version: session.version,
        };
        if (!params.date || !params.time) { form.showError('Укажите дату и время начала.'); return; }
        if (!params.room_id) { form.showError('Выберите кабинет.'); return; }
        if (!reason) { form.showError('Укажите причину переноса.'); return; }

        form.setBusy(true);
        try {
            await api('moveSession', params);
        } catch (err) { reportFailure(form, err); return; }
        closeGradePop();
        toast('Сеанс перенесён');
        o.onDone();
    });

    openGradePopPositioned(form.pop, o.anchor);
}

export function openCancelSessionForm(o) {
    const { api, session } = o;
    const form = openForm(o, { title: 'Отменить сеанс', fieldsHtml: '', submit: 'Отменить сеанс', close: 'Не отменять' });
    if (!form) { return; }

    form.$('[data-esa="ok"]').addEventListener('click', async () => {
        const reason = form.$('#esReason').value.trim();
        if (!reason) { form.showError('Укажите причину отмены.'); return; }
        if (!await confirmDialog('Отменить сеанс? Записи участников будут отменены, они получат уведомление с причиной.', 'Отменить сеанс', 'Не отменять')) { return; }

        form.setBusy(true);
        try {
            await api('cancelSession', { session_id: session.id, reason, version: session.version });
        } catch (err) { reportFailure(form, err); return; }
        closeGradePop();
        toast('Сеанс отменён');
        o.onDone();
    });

    openGradePopPositioned(form.pop, o.anchor);
}
