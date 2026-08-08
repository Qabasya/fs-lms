/* ══════════════════════════════════════════════════════════════════════
   КТП: поповеры/меню размещённой темы (дедлайны, запись занятия, «⋮»).
   Стейта у модуля нет: `api` (шов расписания), `reload` (перерисовка ядра,
   loadCalendar) и `getThemes` (ленивое чтение тем) передаёт ядро.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openCtxMenuRaw, closeCtxMenu } from '../utils.js';
import { icoContinue } from '../../common/icons.js';
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
        openRecordingPopover(el.dataset.glid, el, el.dataset.url || '', api, reload);
    });
}

function openRecordingPopover(glid, anchorEl, currentUrl, api, reload) {
    const html = `
        <div class="wd-pop rec-pop">
            <div class="ctx-title">Ссылка на запись занятия</div>
            <input type="text" class="wd-input rec-input" value="${esc(currentUrl)}" placeholder="https://… или s3://bucket/key">
            <div class="rec-actions">
                <button type="button" class="prof-btn prof-btn-sm prof-btn-primary rec-save">Сохранить</button>
                ${currentUrl ? '<button type="button" class="prof-btn prof-btn-sm rec-clear">Снять ссылку</button>' : ''}
            </div>
        </div>`;
    openCtxMenuRaw(html, anchorEl);
    const menu = document.getElementById('profCtxMenu');
    const input = menu?.querySelector('.rec-input');
    const saveBtn = menu?.querySelector('.rec-save');
    const clearBtn = menu?.querySelector('.rec-clear');
    if (!saveBtn) return;

    const save = async (url) => {
        saveBtn.disabled = true;
        try {
            await api('setRecordingUrl', { group_lesson_id: glid, recording_url: url });
            toast(url ? 'Ссылка сохранена' : 'Ссылка снята');
            closeCtxMenu();
            await reload();
        } catch (e) { toast(e.message, 'error'); saveBtn.disabled = false; }
    };

    saveBtn.addEventListener('click', () => save(input.value.trim()));
    clearBtn?.addEventListener('click', () => save(''));
}

/* ── Продолжение темы на вторую дату (T12.6, D14) ─────────────────────────
   «⋮» на размещённой теме → «Продолжить на другую дату» → в банке появляется
   связанная непристроенная копия — перетащите её на целевую дату тем же
   drag-flow, что и обычную тему. */
export function attachThemeActionsClick(btn, api, reload) {
    btn.addEventListener('click', e => {
        e.stopPropagation(); // не открывать поповер дедлайнов родительской темы
        openThemeActionsMenu(btn.dataset.glid, btn, api, reload);
    });
}

function openThemeActionsMenu(glid, anchorEl, api, reload) {
    const html = `
        <div class="ctx-item" data-act="continue">
            ${icoContinue(16)}
            Продолжить на другую дату
        </div>`;
    openCtxMenuRaw(html, anchorEl);
    const menu = document.getElementById('profCtxMenu');
    const item = menu?.querySelector('[data-act="continue"]');
    if (!item) return;
    item.addEventListener('click', async () => {
        closeCtxMenu();
        try {
            await api('continue', { group_lesson_id: glid });
            toast('Тема продолжена — перетащите копию из банка тем на вторую дату');
            await reload();
        } catch (e) { toast(e.message, 'error'); }
    });
}
