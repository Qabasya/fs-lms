/* ══════════════════════════════════════════════════════════════════════
   КТП: поповеры/меню размещённой темы (дедлайны, запись занятия, «⋮»).
   Стейта у модуля нет: `api` (шов расписания), `reload` (перерисовка ядра,
   loadCalendar) и `getThemes` (ленивое чтение тем) передаёт ядро.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openCtxMenuRaw, closeCtxMenu } from '../utils.js';
import { icoCalendar, icoContinue, icoSwap, icoTrash } from '../../common/icons.js';
import { toLocalInputValue, fromLocalInputValue } from './ktp-calendar-model.js';

/* ── Дедлайны работ занятия (T12.3, D13) ─────────────────────────────────
   Клик по размещённой теме → поповер со списком эффективных работ занятия +
   datetime-local на каждую (по умолчанию пусто = дедлайна нет). Доступно
   даже при lock КТП — дедлайны это delivery, не структура/расписание. */
export function attachDeadlinesClick(el, api) {
    el.addEventListener('click', e => {
        e.stopPropagation(); // не запускать переход в плеер по клику на родительскую карточку
        openDeadlinesPopover(el.dataset.glid, el, api);
    });
}

/** Клик по карточке занятия — переход в плеер курса (Этап 2, ★): та же ссылка,
    что у ученика (LearnerService::playerUrl). Занятие без контента — некликабельно.
    getThemes — колбек ядра, читает актуальные темы на момент клика. */
export function attachPlacedThemeClick(el, getThemes) {
    el.addEventListener('click', () => {
        const t = getThemes().find(x => String(x.group_lesson_id) === el.dataset.glid);
        if (t && t.player_url) { window.location.href = t.player_url; }
    });
}

async function openDeadlinesPopover(glid, anchorEl, api) {
    let works;
    try {
        const res = await api('getDeadlines', { group_lesson_id: glid });
        works = res.works || [];
    } catch (e) { toast(e.message, 'error'); return; }

    const html = `
        <div class="wd-pop">
            <div class="ctx-title">Дедлайны работ</div>
            ${works.length ? works.map(w => `
                <div class="wd-row" data-work-id="${w.id}">
                    <span class="wd-title" title="${esc(w.title)}">${esc(w.title)}</span>
                    <input type="datetime-local" class="wd-input" value="${w.deadline ? toLocalInputValue(w.deadline) : ''}">
                </div>`).join('') : '<div class="wd-empty">На этом занятии нет работ.</div>'}
            ${works.length ? '<button type="button" class="prof-btn prof-btn-sm prof-btn-primary wd-save">Сохранить</button>' : ''}
        </div>`;
    openCtxMenuRaw(html, anchorEl);
    const menu = document.getElementById('profCtxMenu');
    const saveBtn = menu?.querySelector('.wd-save');
    if (!saveBtn) return;
    saveBtn.addEventListener('click', async () => {
        const deadlines = {};
        menu.querySelectorAll('.wd-row').forEach(row => {
            const val = row.querySelector('.wd-input').value;
            deadlines[row.dataset.workId] = val ? fromLocalInputValue(val) : '';
        });
        saveBtn.disabled = true;
        try {
            await api('saveDeadlines', { group_lesson_id: glid, deadlines: JSON.stringify(deadlines) });
            toast('Дедлайны сохранены');
            closeCtxMenu();
        } catch (e) { toast(e.message, 'error'); saveBtn.disabled = false; }
    });
}

/* ── Ссылка на запись занятия (модуль VideoLibrary) ───────────────────────
   Клик по карточке ведёт в плеер (там видно шаги урока), а клик по камере —
   правка/снятие ссылки записи (З3): авто-матч VideoLibrary мог не сработать,
   тогда ссылку вставляют руками. Ядро ничего не знает о VideoLibrary — просто
   хранит и отдаёт строку-указатель. Delivery, не структура — доступно при lock КТП. */
export function attachRecordingClick(el, api, reload) {
    el.addEventListener('click', e => {
        e.stopPropagation(); // не запускать переход в плеер по клику на родительскую карточку
        openRecordingPopover(el.dataset.glid, el, el.dataset.url || '', el.dataset.link || '', api, reload);
    });
}

/* Одно поле: сервер сам различает запись в хранилище (s3://, её ставит авто-привязка)
   и ссылку на облако (https://). */
function openRecordingPopover(glid, anchorEl, currentUrl, currentLink, api, reload) {
    const html = `
        <div class="wd-pop rec-pop">
            <div class="ctx-title">Запись занятия</div>
            <label class="rec-label" for="recValue">Ссылка на запись (https://… или s3://…)</label>
            <input type="text" id="recValue" class="wd-input rec-input" data-rec="recording" value="${esc(currentUrl || currentLink)}" placeholder="https://… или s3://bucket/key">
            <div class="rec-actions">
                <button type="button" class="prof-btn prof-btn-sm prof-btn-primary rec-save">Сохранить</button>
                ${currentUrl || currentLink ? '<button type="button" class="prof-btn prof-btn-sm rec-clear">Снять всё</button>' : ''}
            </div>
        </div>`;
    openCtxMenuRaw(html, anchorEl);
    const menu = document.getElementById('profCtxMenu');
    const saveBtn = menu?.querySelector('.rec-save');
    const clearBtn = menu?.querySelector('.rec-clear');
    if (!saveBtn) return;

    const save = async (clear) => {
        const params = { group_lesson_id: glid };
        menu.querySelectorAll('[data-rec]').forEach(input => { params[input.dataset.rec] = clear ? '' : input.value.trim(); });
        saveBtn.disabled = true;
        try {
            await api('setRecordingUrl', params);
            toast(clear ? 'Запись снята' : 'Сохранено');
            closeCtxMenu();
            await reload();
        } catch (e) { toast(e.message, 'error'); saveBtn.disabled = false; }
    };

    saveBtn.addEventListener('click', () => save(false));
    clearBtn?.addEventListener('click', () => save(true));
}

/* ── Продолжение темы на вторую дату (T12.6, D14) ─────────────────────────
   «⋮» на размещённой теме → «Продолжить на другую дату» → вторая часть темы
   встаёт следующим занятием, непроведённые темы после неё сдвигаются на одно
   занятие вперёд (сервер, ScheduleReflowService::placeInserted). У самого
   продолжения «⋮» ведёт обратно — «Убрать продолжение»: дата освобождается, темы
   после неё подтягиваются назад (ProgramCompositionService::removeContinuation). */
export function attachThemeActionsClick(btn, api, reload, onEdit, getPool) {
    btn.addEventListener('click', e => {
        e.stopPropagation(); // не открывать поповер дедлайнов родительской темы
        openThemeActionsMenu(btn.dataset.glid, btn, api, reload, btn.dataset.kind, onEdit, '1' === btn.dataset.replace ? getPool : null);
    });
}

/* «Провели не ту тему»: второй шаг меню — выбор темы из пула, которая встанет на это занятие.
   Дата, посещаемость и запись занятия остаются, прежняя тема уходит в пул как будущая
   вместе с решениями и оценками учеников. */
function openReplaceMenu(glid, anchorEl, api, reload, pool) {
    const items = pool.length
        ? pool.map(t => `<div class="ctx-item" data-pool="${t.group_lesson_id}">${esc(t.topic || 'Без названия')}</div>`).join('')
        : '<div class="ctx-item ctx-item--empty">В пуле нет тем без даты</div>';
    openCtxMenuRaw(`<div class="ctx-title">Какая тема была на самом деле?</div>${items}`, anchorEl);
    document.getElementById('profCtxMenu')?.querySelectorAll('[data-pool]').forEach(item => item.addEventListener('click', async () => {
        closeCtxMenu();
        try {
            const res = await api('replaceTopic', { group_lesson_id: glid, pool_group_lesson_id: item.dataset.pool });
            const warnings = (res && res.warnings) || [];
            toast(warnings.length
                ? `Тема занятия заменена — прежняя вернулась в пул. Внимание: ${warnings.join('; ')}`
                : 'Тема занятия заменена — прежняя тема вернулась в пул', warnings.length ? 'error' : 'ok');
            await reload();
        } catch (e) { toast(e.message, 'error'); }
    }));
}

/* `onEdit(glid, anchor)` — только в индивидуальной группе: пункт «Изменить дату и время» (перенос занятия). */
function openThemeActionsMenu(glid, anchorEl, api, reload, kind, onEdit, getPool) {
    const remove = 'remove' === kind;
    const items = [];
    if (onEdit) items.push(`<div class="ctx-item" data-act="edit">${icoCalendar(16)}Изменить дату и время</div>`);
    if ('none' !== kind) {
        items.push(remove
            ? `<div class="ctx-item" data-act="remove">${icoTrash(16)}Убрать продолжение</div>`
            : `<div class="ctx-item" data-act="continue">${icoContinue(16)}Продолжить на другую дату</div>`);
    }
    if (getPool) items.push(`<div class="ctx-item" data-act="replace">${icoSwap(16)}Заменить тему занятия…</div>`);
    openCtxMenuRaw(items.join(''), anchorEl);
    const menu = document.getElementById('profCtxMenu');
    menu?.querySelectorAll('[data-act]').forEach(item => item.addEventListener('click', async () => {
        closeCtxMenu();
        const act = item.dataset.act;
        if ('edit' === act) { onEdit(glid, anchorEl); return; }
        if ('replace' === act) { openReplaceMenu(glid, anchorEl, api, reload, getPool()); return; }
        try {
            if ('remove' === act) {
                await api('removeContinuation', { group_lesson_id: glid });
                toast('Продолжение убрано — следующие темы подтянуты назад');
            } else {
                await api('continue', { group_lesson_id: glid });
                toast('Тема продолжена на следующее занятие — следующие темы сдвинуты');
            }
            await reload();
        } catch (e) { toast(e.message, 'error'); }
    }));
}
