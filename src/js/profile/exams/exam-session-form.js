/* ══════════════════════════════════════════════════════════════════════
   Форма сеанса экзамена (этап 4.3): одна форма для кнопки «+ Сеанс», для перетаскивания варианта на день и для правки.
   Поповер `prof-grade-pop` + `gp-form` — как у индивидуального занятия (indi-modal.js).

   Поля: дата, время начала, вариант, кабинет. Окончание, число мест и ответственный — только текст: окончание считается
   из длительности формата варианта, места берутся из кабинета, ответственный — владелец проведения (поля ввода нет).
   Ошибка сервера показывается внутри формы, введённое не теряется.

   Вызов: openSessionForm({ api, anchor, event, variants, rooms, fixed:{date?, assessmentId?}, edit?, onSaved }).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openGradePopPositioned, closeGradePop, fmtDate, plural } from '../utils.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { endTime } from './exam-common.js';
import { openMoveSessionForm, openCancelSessionForm } from './exam-session-actions.js';

const DEFAULT_TIME = '10:00';
const STALE_CODE = 'X-STALE';
const LOCKED_TEXT = 'Сеанс уже начат: общие параметры менять нельзя.';
const PEOPLE_TEXT = 'В сеансе есть участники: перенос и отмена — с причиной, участники получат уведомление.';
const STALE_TEXT = 'Сеанс изменили в другой вкладке. Обновите календарь.';

function variantOptions(variants, selectedId) {
    return variants.map(v =>
        `<option value="${v.id}" ${String(v.id) === String(selectedId) ? 'selected' : ''}>${esc(v.title)}</option>`
    ).join('');
}

/* Кабинет сеанса, который уже нельзя выбрать (отключён, без вместимости), остаётся в списке — иначе правка тихо сменила бы его. */
function roomOptions(rooms, selectedId, edit) {
    const list = rooms.map(r =>
        `<option value="${r.id}" ${String(r.id) === String(selectedId) ? 'selected' : ''}>${esc(r.name)} · ${r.seats} ${plural(r.seats, 'место', 'места', 'мест')}</option>`
    );
    if (edit && edit.room_id && !rooms.some(r => String(r.id) === String(edit.room_id))) {
        list.push(`<option value="${edit.room_id}" selected>${esc(edit.room_name || 'Кабинет')}</option>`);
    }
    return '<option value="">— выберите кабинет —</option>' + list.join('');
}

export function openSessionForm(o) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return; }

    const { api, event, variants = [], rooms = [], onSaved } = o;
    const edit = o.edit || null;
    const fixed = o.fixed || {};
    const locked = !!(edit && edit.is_locked);

    const initDate = edit ? edit.date : (fixed.date || event.period_from);
    const initTime = edit ? edit.time_start : DEFAULT_TIME;
    const initVariant = edit ? edit.assessment_id : (fixed.assessmentId || event.default_assessment_id || (variants[0] && variants[0].id) || '');
    const initRoom = edit ? edit.room_id : (rooms.length === 1 ? rooms[0].id : '');
    const dateFixed = !!fixed.date && !edit;
    const lockAttr = locked ? ' disabled' : '';

    pop.innerHTML = `
        <div class="gp-form gp-indi gp-exam">
        <div class="gp-title">${edit ? 'Правка сеанса' : 'Сеанс экзамена'}</div>
        ${locked ? `<div class="gp-warn">${esc(LOCKED_TEXT)}</div>` : ''}
        ${hasPeople ? `<div class="gp-warn">${esc(PEOPLE_TEXT)}</div>` : ''}
        ${dateFixed
            ? `<div class="gp-field"><span>Дата</span><b class="gp-fixed">${esc(fmtDate(initDate))}</b><input type="hidden" id="esDate" value="${esc(initDate)}"></div>`
            : `<label class="gp-field"><span>Дата</span><input type="date" id="esDate" value="${esc(initDate)}" min="${esc(event.period_from)}" max="${esc(event.period_to)}"${lockAttr}></label>`}
        <label class="gp-field"><span>Время начала</span><input type="time" id="esTime" value="${esc(initTime)}"${lockAttr}></label>
        <div class="gp-field"><span>Окончание</span><b class="gp-fixed" id="esEnd"></b></div>
        <label class="gp-field"><span>Вариант</span><select id="esVariant"${lockAttr}>${variantOptions(variants, initVariant)}</select></label>
        <div class="gp-warn" id="esPublicWarn" hidden>Этот вариант доступен свободно вместе с решениями.</div>
        <label class="gp-field"><span>Кабинет</span><select id="esRoom"${lockAttr}>${roomOptions(rooms, initRoom, edit)}</select></label>
        <div class="gp-field"><span>Мест</span><b class="gp-fixed" id="esSeats"></b></div>
        <div class="gp-field"><span>Ответственный</span><b class="gp-fixed">${esc(event.owner_name || '—')}</b></div>
        <div class="gp-error" id="esError" role="alert" hidden></div>
        <div class="gp-row">
            ${frozen ? '' : `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-es="save">${edit ? 'Сохранить' : 'Добавить'}</button>`}
            ${hasPeople ? '<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-es="move">Перенести сеанс</button><button type="button" class="prof-btn prof-btn-sm" data-es="cancel-session">Отменить сеанс</button>' : ''}
            ${edit && !frozen ? '<button type="button" class="prof-btn prof-btn-sm" data-es="delete">Удалить сеанс</button>' : ''}
            <button type="button" class="prof-btn prof-btn-sm" data-es="cancel">${frozen ? 'Закрыть' : 'Отмена'}</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector(sel);
    const durationOf = () => {
        const v = variants.find(x => String(x.id) === $('#esVariant').value);
        return v ? Number(v.duration_minutes) : NaN;
    };
    const roomSeats = () => {
        const r = rooms.find(x => String(x.id) === $('#esRoom').value);
        return r ? r.seats : (edit && String($('#esRoom').value) === String(edit.room_id) ? edit.capacity : '—');
    };
    const refreshDerived = () => {
        $('#esPublicWarn').hidden = !(variants.find(x => String(x.id) === $('#esVariant').value)?.public);
        const end = endTime($('#esTime').value, durationOf());
        $('#esEnd').textContent = end ? `до ${end}` : '—';
        $('#esSeats').textContent = String(roomSeats());
    };
    const showError = (text, withRefresh = false) => {
        const box = $('#esError');
        box.hidden = false;
        box.innerHTML = esc(text) + (withRefresh ? ' <button type="button" class="prof-btn prof-btn-sm" data-es="refresh">Обновить</button>' : '');
        const refresh = box.querySelector('[data-es="refresh"]');
        if (refresh) { refresh.addEventListener('click', () => { closeGradePop(); onSaved(); }); }
    };
    const setBusy = busy => pop.querySelectorAll('[data-es]').forEach(btn => { btn.disabled = busy; });
    const fail = err => {
        setBusy(false);
        showError(STALE_CODE === err.code ? STALE_TEXT : err.message, STALE_CODE === err.code);
    };

    $('#esTime').addEventListener('input', refreshDerived);
    $('#esVariant').addEventListener('change', refreshDerived);
    $('#esRoom').addEventListener('change', refreshDerived);
    refreshDerived();

    $('[data-es="cancel"]').addEventListener('click', closeGradePop);

    const peopleAction = (selector, open) => $(selector)?.addEventListener('click', () => open({ api, anchor: o.anchor, session: edit, rooms, onDone: onSaved }));
    peopleAction('[data-es="move"]', openMoveSessionForm);
    peopleAction('[data-es="cancel-session"]', openCancelSessionForm);

    const save = $('[data-es="save"]');
    if (save) {
        save.addEventListener('click', async () => {
            const params = {
                event_id: event.id,
                date: $('#esDate').value,
                time: $('#esTime').value,
                assessment_id: $('#esVariant').value,
                room_id: $('#esRoom').value,
            };
            if (!params.date || !params.time) { showError('Укажите дату и время начала.'); return; }
            if (!params.assessment_id) { showError('Выберите вариант.'); return; }
            if (!params.room_id) { showError('Выберите кабинет.'); return; }
            if (edit) { params.session_id = edit.id; params.version = edit.version; }

            setBusy(true);
            try {
                await api('saveSession', params);
            } catch (err) { fail(err); return; }
            closeGradePop();
            toast(edit ? 'Сеанс сохранён' : 'Сеанс добавлен');
            onSaved();
        });
    }

    const del = $('[data-es="delete"]');
    if (del) {
        del.addEventListener('click', async () => {
            const ok = await confirmDialog('Удалить сеанс? Он будет удалён без возможности восстановления.', 'Удалить сеанс', 'Не удалять');
            if (!ok) { return; }
            setBusy(true);
            try {
                await api('deleteSession', { session_id: edit.id });
            } catch (err) { fail(err); return; }
            closeGradePop();
            toast('Сеанс удалён');
            onSaved();
        });
    }

    openGradePopPositioned(pop, o.anchor);
}
