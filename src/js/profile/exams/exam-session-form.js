/* ══════════════════════════════════════════════════════════════════════
   Форма сеанса экзамена (этап 4.3): одна форма для кнопки «+ Сеанс», для перетаскивания варианта на день и для правки.
   Поповер `prof-grade-pop` + `gp-form` — как у индивидуального занятия (indi-modal.js).

   Поля: дата, время начала, вариант, кабинет. Окончание считается из длительности формата варианта и показывается рядом с временем начала.
   Число мест берётся из кабинета, ответственный — владелец проведения: ни того, ни другого в форме нет (сервер подставляет сам).
   «Отображать у учеников» (по умолчанию да): выключено — сеанс видят и записываются на него только гости; выключить можно, лишь когда
   в проведении включена запись гостей по ссылкам школ (иначе флажок заблокирован).
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
    const cancelled = !!(edit && 'cancelled' === edit.status);
    // Заморожен: попытка начата или сеанс отменён — сохранить нельзя. Удалить можно отменённый сеанс и сеанс без записанных
    // (в том числе если все записавшиеся отменили записи). Участники: есть записанные на живой сеанс.
    const frozen = locked || cancelled;
    const hasPeople = !!edit && !cancelled && Number(edit.occupied) > 0;

    const initDate = edit ? edit.date : (fixed.date || event.period_from);
    const initTime = edit ? edit.time_start : DEFAULT_TIME;
    const initVariant = edit ? edit.assessment_id : (fixed.assessmentId || event.default_assessment_id || (variants[0] && variants[0].id) || '');
    const initRoom = edit ? edit.room_id : (rooms.length === 1 ? rooms[0].id : '');
    const dateFixed = !!fixed.date && !edit;
    const lockAttr = locked ? ' disabled' : '';
    const guestsOn = !!event.guest_registration_enabled;
    const initVisible = edit ? edit.visible_to_students !== false : true;

    pop.innerHTML = `
        <div class="gp-form gp-indi gp-exam">
        <div class="gp-title">${edit ? 'Правка сеанса' : 'Сеанс экзамена'}</div>
        ${locked ? `<div class="gp-warn">${esc(LOCKED_TEXT)}</div>` : ''}
        ${hasPeople ? `<div class="gp-warn">${esc(PEOPLE_TEXT)}</div>` : ''}
        ${dateFixed
            ? `<div class="gp-field"><span>Дата</span><b class="gp-fixed">${esc(fmtDate(initDate))}</b><input type="hidden" id="esDate" value="${esc(initDate)}"></div>`
            : `<label class="gp-field"><span>Дата</span><input type="date" id="esDate" value="${esc(initDate)}" min="${esc(event.period_from)}" max="${esc(event.period_to)}"${lockAttr}></label>`}
        <div class="gp-field"><span>Время</span><div class="gp-time"><input type="time" id="esTime" value="${esc(initTime)}" aria-label="Время начала"${lockAttr}><span class="gp-dash">–</span><b class="gp-fixed" id="esEnd" title="Окончание: по длительности варианта"></b></div></div>
        <label class="gp-field"><span>Вариант</span><select id="esVariant"${lockAttr}>${variantOptions(variants, initVariant)}</select></label>
        <label class="gp-field"><span>Кабинет</span><select id="esRoom"${lockAttr}>${roomOptions(rooms, initRoom, edit)}</select></label>
        <label class="gp-field"><span>Отображать у учеников</span><input type="checkbox" id="esVisible" ${initVisible || !guestsOn ? 'checked' : ''}${locked || !guestsOn ? ' disabled' : ''}></label>
        ${guestsOn ? '' : '<div class="gp-hint">Скрыть сеанс от учеников можно, когда в настройках проведения включена запись гостей по ссылкам школ.</div>'}
        <div class="gp-error" id="esError" role="alert" hidden></div>
        <div class="gp-row">
            ${frozen ? '' : `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-es="save">${edit ? 'Сохранить' : 'Добавить'}</button>`}
            ${hasPeople ? '<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-es="move">Перенести сеанс</button><button type="button" class="prof-btn prof-btn-sm" data-es="cancel-session">Отменить сеанс</button>' : ''}
            ${edit && !locked && !hasPeople ? '<button type="button" class="prof-btn prof-btn-sm" data-es="delete">Удалить сеанс</button>' : ''}
            <button type="button" class="prof-btn prof-btn-sm" data-es="cancel">${frozen ? 'Закрыть' : 'Отмена'}</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector(sel);
    const durationOf = () => {
        const v = variants.find(x => String(x.id) === $('#esVariant').value);
        return v ? Number(v.duration_minutes) : NaN;
    };
    const refreshDerived = () => {
        const end = endTime($('#esTime').value, durationOf());
        $('#esEnd').textContent = end || '—';
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
                visible_to_students: $('#esVisible').checked ? 1 : 0,
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
