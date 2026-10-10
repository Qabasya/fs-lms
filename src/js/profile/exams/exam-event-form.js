/* ══════════════════════════════════════════════════════════════════════
   «Настройки проведения» (этап 4.5): название, описание для участника, период «с — по», окно записи, основной вариант,
   запись гостей по ссылкам школ; форма отмены проведения с обязательной причиной.
   Поповер `prof-grade-pop` + `gp-form`, как у формы сеанса. Клиентская проверка до отправки дублирует серверную, не заменяет:
   ошибка показывается у поля, при отказе сервера — под формой, введённое не теряется.

   Вызов: openEventForm({ api, anchor, subjectKey, event?, variants, canManageGuests, launch, onSaved(eventId) }).
   `launch` — чек-лист готовности гостевой записи ({items, ready}) с сервера: пока он не пройден, включить «Запись гостей» нельзя.
          openCancelForm({ api, anchor, event, onDone }).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openGradePopPositioned, closeGradePop } from '../utils.js';
import { toDateTimeLocal, validateEventForm } from './exam-common.js';
import { renderSourcesSection, gradeOfDirection } from './exam-sources.js';

const STALE_CODE = 'X-STALE';
const STALE_TEXT = 'Проведение изменили в другой вкладке. Обновите страницу.';

function variantOptions(variants, selectedId) {
    return '<option value="">— не выбран —</option>' + variants.map(v =>
        `<option value="${v.id}" ${String(v.id) === String(selectedId) ? 'selected' : ''}>${esc(v.title)}</option>`
    ).join('');
}

function fieldError(name) {
    return `<div class="gp-error" data-err="${name}" hidden></div>`;
}

/** Чек-лист запуска гостевой записи: галочки и подсказки по непройденным пунктам; пройден целиком — одна строка. */
function launchChecklistHtml(launch) {
    if (launch.ready) { return '<div class="gp-field"><span></span><div class="exam-launch is-ready">Настройки запуска гостевой записи в порядке.</div></div>'; }
    return `<div class="gp-field gp-field--top"><span>Готовность</span><ul class="exam-launch">${launch.items.map(i =>
        `<li class="${i.ok ? 'is-ok' : 'is-fail'}">${i.ok ? '✔' : '✖'} ${esc(i.label)}${i.ok ? '' : ` — ${esc(i.hint)}`}</li>`).join('')}</ul></div>
        <div class="gp-warn">Пока есть непройденный пункт, включить «Запись гостей» нельзя.</div>`;
}

export function openEventForm(o) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return; }

    const { api, subjectKey, variants = [], onSaved } = o;
    const event = o.event || null;
    const guestReady = !!o.canManageGuests;
    const launch = o.launch || { items: [], ready: true };
    const guestsOn = !!(event && event.guest_registration_enabled);
    const today = new Date().toISOString().slice(0, 10);

    pop.innerHTML = `
        <div class="gp-form gp-indi gp-exam gp-event">
        <div class="gp-title">${event ? 'Настройки проведения' : 'Новое проведение'}</div>
        <label class="gp-field"><span>Название</span><input type="text" id="evTitle" maxlength="255" value="${esc(event ? event.title : '')}"></label>
        ${fieldError('title')}
        <label class="gp-field gp-field--top"><span>Описание для участника</span><textarea id="evDescription" rows="3">${esc(event ? event.description : '')}</textarea></label>
        <div class="gp-field"><span>Период</span><div class="gp-time">
            <input type="date" id="evFrom" value="${esc(event ? event.period_from : today)}" aria-label="Период с">
            <span class="gp-dash">–</span>
            <input type="date" id="evTo" value="${esc(event ? event.period_to : today)}" aria-label="Период по">
        </div></div>
        ${fieldError('period_from')}
        <div class="gp-field"><span>Запись открыта</span><div class="gp-time">
            <input type="datetime-local" id="evOpens" value="${esc(toDateTimeLocal(event ? event.registration_opens_at : ''))}" aria-label="Запись открыта с">
            <span class="gp-dash">–</span>
            <input type="datetime-local" id="evCloses" value="${esc(toDateTimeLocal(event ? event.registration_closes_at : ''))}" aria-label="Запись открыта по">
        </div></div>
        ${fieldError('registration_opens_at')}
        <label class="gp-field"><span>Основной вариант</span><select id="evVariant">${variantOptions(variants, event ? event.default_assessment_id : '')}</select></label>
        ${guestReady ? `<label class="gp-field gp-field--check"><input type="checkbox" id="evGuests" ${guestsOn ? 'checked' : ''}${launch.ready || guestsOn ? '' : ' disabled'}><span>Запись гостей по ссылкам школ</span></label>` : ''}
        ${guestReady ? launchChecklistHtml(launch) : ''}
        ${guestReady && event ? '<div class="gp-sources" id="evSources"></div>' : ''}
        <div class="gp-error" id="evError" role="alert" hidden></div>
        <div class="gp-row">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-ev="save">${event ? 'Сохранить' : 'Создать'}</button>
            <button type="button" class="prof-btn prof-btn-sm" data-ev="cancel">Отмена</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector(sel);
    const showFieldErrors = errors => {
        pop.querySelectorAll('[data-err]').forEach(box => {
            const text = errors[box.dataset.err] || '';
            box.hidden = !text;
            box.textContent = text;
        });
    };
    const showFormError = (text, withRefresh = false) => {
        const box = $('#evError');
        box.hidden = false;
        box.innerHTML = esc(text) + (withRefresh ? ' <button type="button" class="prof-btn prof-btn-sm" data-ev="refresh">Обновить</button>' : '');
        const refresh = box.querySelector('[data-ev="refresh"]');
        if (refresh) { refresh.addEventListener('click', () => { closeGradePop(); onSaved(event ? event.id : 0); }); }
    };

    $('[data-ev="cancel"]').addEventListener('click', closeGradePop);
    $('[data-ev="save"]').addEventListener('click', async () => {
        const values = {
            title: $('#evTitle').value,
            period_from: $('#evFrom').value,
            period_to: $('#evTo').value,
            registration_opens_at: $('#evOpens').value,
            registration_closes_at: $('#evCloses').value,
        };
        const errors = validateEventForm(values);
        showFieldErrors(errors);
        if (Object.keys(errors).length) { return; }

        const save = $('[data-ev="save"]');
        save.disabled = true;
        const params = {
            subject_key: subjectKey,
            title: values.title.trim(),
            description: $('#evDescription').value,
            period_from: values.period_from,
            period_to: values.period_to,
            registration_opens_at: values.registration_opens_at.replace('T', ' '),
            registration_closes_at: values.registration_closes_at.replace('T', ' '),
            default_assessment_id: $('#evVariant').value || 0,
            guest_registration_enabled: guestReady && $('#evGuests') && $('#evGuests').checked ? 1 : 0,
        };
        if (event) { params.event_id = event.id; params.version = event.version; }

        try {
            const res = await api('saveEvent', params);
            closeGradePop();
            toast(event ? 'Настройки сохранены' : 'Проведение создано');
            onSaved(res.event.id);
        } catch (err) {
            save.disabled = false;
            showFormError(STALE_CODE === err.code ? STALE_TEXT : err.message, STALE_CODE === err.code);
        }
    });

    if (guestReady && event) {
        const main = variants.find(v => String(v.id) === String(event.default_assessment_id));
        renderSourcesSection($('#evSources'), { api, event, grade: gradeOfDirection(main && main.direction) });
    }

    openGradePopPositioned(pop, o.anchor);
}

/** Отмена проведения: причина обязательна. Пока есть записи, сервер откажет — текст отказа показывается в форме. */
export function openCancelForm(o) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return; }

    const { api, event, onDone } = o;

    pop.innerHTML = `
        <div class="gp-form gp-indi gp-exam">
        <div class="gp-title">Отмена проведения</div>
        <label class="gp-field gp-field--top"><span>Причина</span><textarea id="evReason" rows="3" placeholder="Что сообщить участникам"></textarea></label>
        <div class="gp-error" id="evCancelError" role="alert" hidden></div>
        <div class="gp-row">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-ev="confirm">Отменить проведение</button>
            <button type="button" class="prof-btn prof-btn-sm" data-ev="close">Не отменять</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector(sel);
    const showError = text => {
        const box = $('#evCancelError');
        box.hidden = false;
        box.textContent = text;
    };

    $('[data-ev="close"]').addEventListener('click', closeGradePop);
    $('[data-ev="confirm"]').addEventListener('click', async () => {
        const reason = $('#evReason').value.trim();
        if (!reason) { showError('Укажите причину отмены.'); return; }

        const btn = $('[data-ev="confirm"]');
        btn.disabled = true;
        try {
            await api('cancelEvent', { event_id: event.id, reason, version: event.version });
        } catch (err) {
            btn.disabled = false;
            showError(STALE_CODE === err.code ? STALE_TEXT : err.message);
            return;
        }
        closeGradePop();
        toast('Проведение отменено');
        onDone();
    });

    openGradePopPositioned(pop, o.anchor);
}
