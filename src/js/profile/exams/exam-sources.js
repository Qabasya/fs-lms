/* ══════════════════════════════════════════════════════════════════════
   «Ссылки для преподавателей» (этап 4.6): источники приглашений проведения — школа, класс, преподаватель — и их ссылки.
   Секция внутри формы «Настройки проведения»; видна только при cfg.guestSignupReady (до этапа 11a скрыта).

   Открытый ключ ссылки приходит один раз — в ответе выдачи или перевыпуска — и держится только в памяти открытой формы:
   «Скопировать» работает, пока форма открыта; после закрытия остаётся «Перевыпустить». Список источников ключей и хешей не содержит.
   Класс определяется направлением основного варианта проведения и показывается текстом: выбрать его нельзя.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast } from '../utils.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { copyToClipboard } from '../../common/utils.js';

/** Последствия перевыпуска — дословно из SPEC §6: человек должен понять, что произойдёт со старой ссылкой. */
export const REISSUE_TEXT = 'Старая ссылка перестанет работать сразу, уже открытые по ней формы потеряют доступ; '
    + 'оплаченные записи и действующие брони сохраняются; новую ссылку нужно отправить школе заново.';

const REVOKE_TEXT = 'Ссылка перестанет работать сразу: новые заявки по ней станут невозможны. Оплаченные записи и действующие брони сохранятся.';

/** Класс по направлению основного варианта: ЕГЭ — 11, ОГЭ — 9; без варианта направления нет. */
export function gradeOfDirection(direction) {
    return 'ege' === direction ? 11 : ('oge' === direction ? 9 : 0);
}

/**
 * Строка источника. `canCopy` — ключ этой ссылки ещё в памяти формы.
 *
 * @param {{id:number, school_name:string, teacher_name:string, grade:number, is_active:boolean, has_link:boolean, active_holds:number}} source
 * @param {boolean} canCopy
 */
export function sourceRowHtml(source, canCopy) {
    const link = source.has_link
        ? `${canCopy ? `<button type="button" class="prof-btn prof-btn-sm" data-src="copy">Скопировать</button>` : ''}
           <button type="button" class="prof-btn prof-btn-sm" data-src="reissue">Перевыпустить</button>
           <button type="button" class="prof-btn prof-btn-sm" data-src="revoke">Отозвать</button>`
        : '<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-src="issue">Создать ссылку</button>';

    return `<div class="exam-source${source.is_active ? '' : ' is-off'}" data-id="${source.id}">
        <div class="es-main">
            <div class="es-school">${esc(source.school_name)} · ${source.grade} класс</div>
            <div class="es-teacher">${esc(source.teacher_name)}${source.active_holds ? ` · броней: ${source.active_holds}` : ''}</div>
        </div>
        <label class="es-toggle"><input type="checkbox" data-src="toggle" ${source.is_active ? 'checked' : ''}> активна</label>
        <div class="es-actions">${link}</div>
    </div>`;
}

/**
 * @param {HTMLElement} container
 * @param {{api: Function, event: {id:number}, grade: number}} o `grade` — 11/9 по направлению основного варианта, 0 — варианта нет.
 */
export function renderSourcesSection(container, o) {
    if (!container) { return; }
    const { api, event, grade } = o;
    let sources = [];
    const urls = new Map(); // id источника → адрес со свежим ключом; только пока открыта форма

    const draw = () => {
        container.innerHTML = `
            <div class="gp-title">Ссылки для преподавателей</div>
            <div class="es-list">${sources.length
                ? sources.map(s => sourceRowHtml(s, urls.has(s.id))).join('')
                : '<div class="es-empty">Ссылок пока нет.</div>'}</div>
            ${grade
                ? `<div class="es-add">
                    <input type="text" id="srcSchool" placeholder="Школа" maxlength="255" aria-label="Школа">
                    <span class="es-grade" title="Класс определяется направлением проведения">${grade} класс</span>
                    <input type="text" id="srcTeacher" placeholder="ФИО преподавателя" maxlength="255" aria-label="ФИО преподавателя">
                    <button type="button" class="prof-btn prof-btn-sm" data-src="add">Добавить</button>
                </div>`
                : '<div class="es-empty">Выберите основной вариант проведения: от него зависит класс.</div>'}`;
        wire();
    };

    const reload = async () => {
        try {
            sources = (await api('getSources', { event_id: event.id })).sources || [];
        } catch (err) {
            container.innerHTML = `<div class="gp-error">${esc(err.message)}</div>`;
            return;
        }
        draw();
    };

    const act = async (fn, okText) => {
        try {
            await fn();
            if (okText) { toast(okText); }
        } catch (err) {
            toast(err.message, 'error');
        }
        await reload();
    };

    function wire() {
        container.querySelector('[data-src="add"]')?.addEventListener('click', () => act(async () => {
            await api('saveSource', {
                event_id: event.id,
                school_name: container.querySelector('#srcSchool').value,
                teacher_name: container.querySelector('#srcTeacher').value,
                grade,
            });
        }, 'Источник добавлен'));

        container.querySelectorAll('.exam-source').forEach(row => {
            const id = Number(row.dataset.id);
            row.querySelector('[data-src="toggle"]')?.addEventListener('change', e => act(() => api('toggleSource', { source_id: id, active: e.target.checked ? 1 : 0 })));
            row.querySelector('[data-src="issue"]')?.addEventListener('click', () => act(async () => {
                urls.set(id, (await api('issueSourceLink', { source_id: id })).url);
            }, 'Ссылка создана'));
            row.querySelector('[data-src="copy"]')?.addEventListener('click', async () => {
                const copied = await copyToClipboard(urls.get(id));
                toast(copied ? 'Ссылка скопирована' : 'Не удалось скопировать', copied ? 'ok' : 'error');
            });
            row.querySelector('[data-src="reissue"]')?.addEventListener('click', async () => {
                if (!await confirmDialog(REISSUE_TEXT, 'Перевыпустить', 'Не перевыпускать')) { return; }
                await act(async () => { urls.set(id, (await api('reissueSourceLink', { source_id: id })).url); }, 'Ссылка перевыпущена');
            });
            row.querySelector('[data-src="revoke"]')?.addEventListener('click', async () => {
                if (!await confirmDialog(REVOKE_TEXT, 'Отозвать', 'Не отзывать')) { return; }
                await act(async () => { await api('revokeSourceLink', { source_id: id }); urls.delete(id); }, 'Ссылка отозвана');
            });
        });
    }

    container.innerHTML = '<div class="es-empty">Загрузка…</div>';
    reload();
}
