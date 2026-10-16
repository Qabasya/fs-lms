/* ══════════════════════════════════════════════════════════════════════
   «Ссылки для преподавателей» (этап 4.6): источники приглашений проведения — школа, класс, преподаватель — и их ссылки.
   Секция внутри формы «Настройки проведения»; видна при праве на гостей (cfg.canManageGuests).

   Открытый ключ ссылки приходит один раз — в ответе выдачи или перевыпуска — и держится только в памяти открытой формы:
   «Скопировать» работает, пока форма открыта; после закрытия остаётся «Перевыпустить». Список источников ключей и хешей не содержит.
   Класс определяется направлением основного варианта проведения и показывается текстом: выбрать его нельзя.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast } from '../utils.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { copyToClipboard } from '../../common/utils.js';
import { icoPencil } from '../../common/icons.js';
import { exactSchool, schoolKeyOf } from '../../common/school-suggest.js';
import { initSchoolSuggest } from '../../frontend/services/school-suggest.js';

/** Последствия перевыпуска — дословно из SPEC §6: человек должен понять, что произойдёт со старой ссылкой. */
export const REISSUE_TEXT = 'Старая ссылка перестанет работать сразу, уже открытые по ней формы потеряют доступ; '
    + 'оплаченные записи и действующие брони сохраняются; новую ссылку нужно отправить школе заново.';

const DELETE_TEXT = 'Источник будет удалён без возможности восстановления. Если по нему уже есть заявки или записи, удаление будет отклонено.';

const REVOKE_TEXT = 'Ссылка перестанет работать сразу: новые заявки по ней станут невозможны. Оплаченные записи и действующие брони сохранятся.';

/** Класс по направлению основного варианта: ЕГЭ — 11, ОГЭ — 9; без варианта направления нет. */
export function gradeOfDirection(direction) {
    return 'ege' === direction ? 11 : ('oge' === direction ? 9 : 0);
}

/** Допустимые скидки по ссылке (зеркало `ExamSourceDTO::DISCOUNT_STEPS`): 0 — полная цена, 100 — без оплаты. */
const DISCOUNT_STEPS = [0, 25, 50, 75, 100];

/** Подпись пункта скидки в списке выбора. */
function discountLabel(percent) {
    if (100 === percent) { return '100% — бесплатно'; }
    return 0 === percent ? 'Без скидки' : `Скидка ${percent}%`;
}

/** Плашка скидки в строке источника; у полной цены её нет. */
function discountChipHtml(percent) {
    const p = Number(percent) || 0;
    if (p <= 0) { return ''; }
    return ` <span class="es-free">${100 === p ? 'без оплаты' : `скидка ${p}%`}</span>`;
}

/**
 * Строка источника. `canCopy` — ключ этой ссылки ещё в памяти формы.
 *
 * @param {{id:number, school_name:string, teacher_name:string, grade:number, is_active:boolean, discount_percent?:number, has_link:boolean, active_holds:number}} source
 * @param {boolean} canCopy
 */
export function sourceRowHtml(source, canCopy) {
    const link = source.has_link
        ? `${canCopy || source.url ? `<button type="button" class="prof-btn prof-btn-sm" data-src="copy">Скопировать</button>` : ''}
           <button type="button" class="prof-btn prof-btn-sm" data-src="reissue">Перевыпустить</button>
           <button type="button" class="prof-btn prof-btn-sm" data-src="revoke">Отозвать</button>`
        : '<button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-src="issue">Создать ссылку</button>';
    // Удалить можно только после явного отзыва ссылки: сначала «Отозвать», потом «Удалить».
    const remove = !source.revoked || source.has_link ? '' : '<button type="button" class="prof-btn prof-btn-sm prof-btn-danger" data-src="delete">Удалить</button>';

    return `<div class="exam-source${source.is_active ? '' : ' is-off'}" data-id="${source.id}">
        <div class="es-main">
            <div class="es-school">${esc(source.school_name)} · ${source.grade} класс${discountChipHtml(source.discount_percent)}</div>
            <div class="es-teacher"><span class="es-teacher-name">${esc(source.teacher_name)}</span>
                <button type="button" class="es-edit" data-src="edit-teacher" title="Изменить ФИО преподавателя" aria-label="Изменить ФИО преподавателя">${icoPencil(12)}</button>${source.active_holds ? ` · броней: ${source.active_holds}` : ''}</div>
        </div>
        <label class="es-toggle"><input type="checkbox" data-src="toggle" ${source.is_active ? 'checked' : ''}> активна</label>
        <div class="es-actions">${link} ${remove}</div>
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
    let open = { enabled: false, url: '' }; // открытая запись: гость сам выбирает класс по общему адресу страницы записи
    const urls = new Map(); // id источника → адрес со свежим ключом; только пока открыта форма

    const draw = () => {
        container.innerHTML = `
            <div class="gp-title">Открытая запись</div>
            <label class="gp-field gp-field--check"><input type="checkbox" data-open="toggle" ${open.enabled ? 'checked' : ''}${grade ? '' : ' disabled'}>
                <span>Гости без ссылки школы выбирают класс на странице записи (отчётов школам по ним нет)</span></label>
            ${open.enabled ? `<div class="es-open"><code>${esc(open.url)}</code> <button type="button" class="prof-btn prof-btn-sm" data-open="copy">Скопировать</button></div>` : ''}
            <div class="gp-title">Ссылки для преподавателей</div>
            <div class="es-list">${sources.length
                ? sources.map(s => sourceRowHtml(s, urls.has(s.id))).join('')
                : '<div class="es-empty">Ссылок пока нет.</div>'}</div>
            ${grade
                ? `<div class="es-add">
                    <span class="es-school-field"><input type="text" id="srcSchool" placeholder="Школа" maxlength="255" aria-label="Школа" autocomplete="off"></span>
                    <span class="es-grade" title="Класс определяется направлением проведения">${grade} класс</span>
                    <input type="text" id="srcTeacher" placeholder="ФИО преподавателя" maxlength="255" aria-label="ФИО преподавателя">
                    <select id="srcDiscount" aria-label="Скидка по ссылке" title="Скидка для учеников, пришедших по этой ссылке. 100% — запись без оплаты. Гость видит, что скидка действует из-за ссылки преподавателя">${DISCOUNT_STEPS.map(p => `<option value="${p}">${discountLabel(p)}</option>`).join('')}</select>
                    <button type="button" class="prof-btn prof-btn-sm" data-src="add">Добавить</button>
                </div>`
                : '<div class="es-empty">Выберите основной вариант проведения: от него зависит класс.</div>'}`;
        wire();
    };

    const reload = async () => {
        try {
            const res = await api('getSources', { event_id: event.id });
            sources = res.sources || [];
            open = res.public || open;
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

    /* ФИО преподавателя правится на месте: ссылка и ключ остаются прежними. Enter — сохранить, Esc — отмена. */
    function editTeacher(row, id) {
        const source = sources.find(x => x.id === id);
        const box = row.querySelector('.es-teacher');
        if (!source || !box || box.querySelector('input')) { return; }

        box.innerHTML = `<input type="text" class="es-teacher-input" maxlength="255" aria-label="ФИО преподавателя" value="${esc(source.teacher_name)}">
            <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-src="teacher-save">Сохранить</button>
            <button type="button" class="prof-btn prof-btn-sm" data-src="teacher-cancel">Отмена</button>`;
        const input = box.querySelector('input');
        input.focus();
        input.select();

        const save = () => act(() => api('saveSource', {
            event_id: event.id,
            source_id: id,
            version: source.version,
            school_name: source.school_name,
            school_key: source.school_key || '',
            grade: source.grade,
            teacher_name: input.value,
        }), 'ФИО преподавателя изменено');
        box.querySelector('[data-src="teacher-save"]').addEventListener('click', save);
        box.querySelector('[data-src="teacher-cancel"]').addEventListener('click', draw);
        input.addEventListener('keydown', (e) => {
            if ('Enter' === e.key) { e.preventDefault(); save(); }
            if ('Escape' === e.key) { draw(); }
        });
    }

    function wire() {
        // Подсказки справочника — тот же выпадающий список, что в форме заявки (apply). Вписать школу вручную можно всегда.
        const schoolInput = container.querySelector('#srcSchool');
        initSchoolSuggest(schoolInput);

        container.querySelector('[data-open="toggle"]')?.addEventListener('change', e => act(
            async () => { open = (await api('setPublicRegistration', { event_id: event.id, enabled: e.target.checked ? 1 : 0 })).public; },
            e.target.checked ? 'Открытая запись включена' : 'Открытая запись выключена'
        ));
        container.querySelector('[data-open="copy"]')?.addEventListener('click', async () => {
            const copied = await copyToClipboard(open.url);
            toast(copied ? 'Адрес скопирован' : 'Не удалось скопировать', copied ? 'ok' : 'error');
        });

        container.querySelector('[data-src="add"]')?.addEventListener('click', () => act(async () => {
            // Из справочника — название и ключ; свободный ввод — только название (ключа нет, школы не объединяются по похожей строке).
            const typed = schoolInput.value;
            const known = exactSchool(typed);
            await api('saveSource', {
                event_id: event.id,
                school_name: known || typed,
                school_key: known ? schoolKeyOf(known) : '',
                teacher_name: container.querySelector('#srcTeacher').value,
                discount_percent: Number(container.querySelector('#srcDiscount').value) || 0,
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
                const copied = await copyToClipboard(urls.get(id) || sources.find(x => x.id === id)?.url);
                toast(copied ? 'Ссылка скопирована' : 'Не удалось скопировать', copied ? 'ok' : 'error');
            });
            row.querySelector('[data-src="reissue"]')?.addEventListener('click', async () => {
                if (!await confirmDialog(REISSUE_TEXT, 'Перевыпустить', 'Не перевыпускать')) { return; }
                await act(async () => { urls.set(id, (await api('reissueSourceLink', { source_id: id })).url); }, 'Ссылка перевыпущена');
            });
            row.querySelector('[data-src="edit-teacher"]')?.addEventListener('click', () => editTeacher(row, id));
            row.querySelector('[data-src="delete"]')?.addEventListener('click', async () => {
                if (!await confirmDialog(DELETE_TEXT, 'Удалить', 'Не удалять')) { return; }
                await act(async () => { await api('deleteSource', { source_id: id }); urls.delete(id); }, 'Источник удалён');
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
