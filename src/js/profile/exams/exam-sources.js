/* ══════════════════════════════════════════════════════════════════════
   «Управление ссылками» (этап 4.6): источники приглашений проведения — школа, класс, преподаватель — и их ссылки.
   Панель справа (половина экрана), открывается из «Настроек проведения» при включённой записи гостей по ссылкам школ.

   Одна строка = один источник: школа и число записанных, под ней ФИО (правится на месте) и скидка; справа «Скопировать»,
   выбор сеансов и меню «⋯» (перевыпустить, удалить запись). Аккордеона нет.

   Адрес ссылки приходит с сервера (хранится зашифрованным), поэтому «Скопировать» работает и после закрытия панели;
   у ссылки, выпущенной до хранения ключа, адрес пуст — её нужно перевыпустить.
   Класс определяется направлением основного варианта проведения и показывается текстом: выбрать его нельзя.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, openCtxMenuRaw, closeCtxMenu, fmtDayMonth, openGradePopDrawer, closeGradePop } from '../utils.js';
import { confirmDialog } from '../../common/components/confirm-dialog.js';
import { copyToClipboard } from '../../common/utils.js';
import { icoPencil, icoCopy, icoX, icoCaret, icoCheck } from '../../common/icons.js';
import { exactSchool, schoolKeyOf } from '../../common/school-suggest.js';
import { initSchoolSuggest } from '../../frontend/services/school-suggest.js';

/** Последствия перевыпуска — дословно из SPEC §6: человек должен понять, что произойдёт со старой ссылкой. */
export const REISSUE_TEXT = 'Старая ссылка перестанет работать сразу, уже открытые по ней формы потеряют доступ; '
    + 'оплаченные записи и действующие брони сохраняются; новую ссылку нужно отправить школе заново.';

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
    return `<span class="es-free">${100 === p ? 'без оплаты' : `скидка ${p}%`}</span>`;
}

/** Сеансы, на которые вообще можно записать: отменённые в выбор не попадают. */
function selectableSessions(sessions) {
    return (sessions || []).filter(s => 'cancelled' !== s.status);
}

/** Подпись кнопки выбора сеансов: «Все сеансы» или число отмеченных (только существующих). */
export function sessionsLabel(source, sessions) {
    const known = new Set(selectableSessions(sessions).map(s => Number(s.id)));
    const n = (source.session_ids || []).filter(id => known.has(Number(id))).length;
    return n ? `Сеансы: ${n}` : 'Все сеансы';
}

/**
 * Строка источника.
 *
 * @param {{id:number, school_name:string, teacher_name:string, grade:number, is_active:boolean, discount_percent?:number, has_link:boolean, url?:string, active_holds:number, registered?:number, session_ids?:number[]}} source
 * @param {Array<{id:number, status:string}>} [sessions] сеансы проведения (для подписи выбора сеансов)
 */
export function sourceRowHtml(source, sessions = []) {
    const link = source.has_link
        ? `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary es-copy" data-src="copy" title="${source.url ? 'Скопировать ссылку' : 'Адрес этой ссылки не сохранён — перевыпустите её'}" ${source.url ? '' : 'disabled'}>${icoCopy(13)}<span>Скопировать</span></button>`
        : `<button type="button" class="prof-btn prof-btn-sm prof-btn-primary es-copy" data-src="copy" title="Ссылка создаётся…" disabled>${icoCopy(13)}<span>Скопировать</span></button>`;
    const registered = Number(source.registered) || 0;

    return `<div class="exam-source${source.is_active ? '' : ' is-off'}" data-id="${source.id}">
        <div class="es-main">
            <div class="es-school">${esc(source.school_name)}${source.is_active ? '' : ' <span class="es-off">отключена</span>'} · <span class="es-count" title="Записано по этой ссылке (действующие записи)">Записано: <b>${registered}</b></span></div>
            <div class="es-teacher"><span class="es-teacher-name">${esc(source.teacher_name)}</span>
                <button type="button" class="es-edit" data-src="edit-teacher" title="Изменить ФИО преподавателя" aria-label="Изменить ФИО преподавателя">${icoPencil(12)}</button>
                ${discountChipHtml(source.discount_percent)}${source.active_holds ? `<span class="es-holds">броней: ${source.active_holds}</span>` : ''}</div>
        </div>
        <div class="es-tools">
            ${link}
            <button type="button" class="prof-btn prof-btn-sm es-sessions" data-src="sessions" title="На какие сеансы можно записаться по этой ссылке"><span>${esc(sessionsLabel(source, sessions))}</span>${icoCaret(12, 'es-caret')}</button>
            <button type="button" class="prof-icon-ghost" data-src="menu" aria-label="Действия со ссылкой">⋮</button>
        </div>
    </div>`;
}

/**
 * Панель «Управление ссылками» поверх страницы. Закрытие крестиком и Esc/подложкой — просто закрывает; `onBack` (если задан)
 * вызывается при «← К настройкам», чтобы вернуть форму настроек проведения.
 *
 * @param {{api: Function, event: {id:number, sessions?:Array}, grade: number, onBack?: Function}} o `grade` — 11/9 по направлению основного варианта, 0 — варианта нет.
 */
export function openSourcesDrawer(o) {
    const pop = document.getElementById('profGradePop');
    if (!pop) { return; }

    pop.innerHTML = `<div class="gp-form es-drawer">
        <div class="es-drawer-head">
            ${o.onBack ? '<button type="button" class="prof-btn prof-btn-sm" data-src="back">← К настройкам</button>' : ''}
            <div class="es-drawer-title">Управление ссылками</div>
            <span class="prof-spacer"></span>
            <button type="button" class="prof-icon-ghost" data-src="close" aria-label="Закрыть">${icoX(14)}</button>
        </div>
        <div class="es-drawer-body" id="evSources"></div>
    </div>`;
    pop.querySelector('[data-src="close"]').addEventListener('click', closeGradePop);
    pop.querySelector('[data-src="back"]')?.addEventListener('click', () => o.onBack());

    openGradePopDrawer(pop);
    renderSourcesSection(pop.querySelector('#evSources'), o);
}

/**
 * Содержимое панели: открытая запись, список ссылок и форма добавления школы.
 *
 * @param {HTMLElement} container
 * @param {{api: Function, event: {id:number, sessions?:Array}, grade: number}} o
 */
export function renderSourcesSection(container, o) {
    if (!container) { return; }
    const { api, event, grade } = o;
    const sessions = event.sessions || [];
    let sources = [];
    const issued = new Set(); // источники, которым ссылку уже пробовали выдать (без повторов при сбое)
    let open = { enabled: false, url: '' }; // открытая запись: гость сам выбирает класс по общему адресу страницы записи

    const draw = () => {
        container.innerHTML = `
            <label class="gp-field gp-field--check"><input type="checkbox" data-open="toggle" ${open.enabled ? 'checked' : ''}${grade ? '' : ' disabled'}>
                <span>Гости без ссылки школы выбирают класс на странице записи (отчётов школам по ним нет)</span></label>
            ${open.enabled ? `<div class="es-open"><code>${esc(open.url)}</code> <button type="button" class="prof-btn prof-btn-sm" data-open="copy">Скопировать</button></div>` : ''}
            <div class="gp-title">Ссылки для преподавателей</div>
            <div class="es-list">${sources.length
                ? sources.map(s => sourceRowHtml(s, sessions)).join('')
                : '<div class="es-empty">Ссылок пока нет.</div>'}</div>
            ${grade
                ? `<div class="es-add">
                    <span class="es-school-field"><input type="text" id="srcSchool" placeholder="Школа" maxlength="255" aria-label="Школа" autocomplete="off"></span>
                    <input type="text" id="srcTeacher" placeholder="ФИО преподавателя" maxlength="255" aria-label="ФИО преподавателя">
                    <select id="srcDiscount" aria-label="Скидка по ссылке" title="Скидка для учеников, пришедших по этой ссылке. 100% — запись без оплаты. Гость видит, что скидка действует из-за ссылки преподавателя">${DISCOUNT_STEPS.map(p => `<option value="${p}">${discountLabel(p)}</option>`).join('')}</select>
                    <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-src="add">Добавить</button>
                </div>`
                : '<div class="es-empty">Выберите основной вариант проведения: от него зависит класс.</div>'}`;
        wire();
    };

    const reload = async () => {
        try {
            const res = await api('getSources', { event_id: event.id });
            sources = res.sources || [];
            open = res.public || open;
            // Ссылка создаётся сама: у строки без неё (старые записи) выдаём её здесь, по одному разу за открытие панели.
            // Отозванную раньше ссылку молча не оживляем — её возвращает только осознанный «Перевыпустить» в меню.
            const missing = sources.filter(s => !s.has_link && !s.revoked && !issued.has(s.id));
            if (missing.length) {
                missing.forEach(s => issued.add(s.id));
                await Promise.all(missing.map(s => api('issueSourceLink', { source_id: s.id }).catch(() => null)));
                const again = await api('getSources', { event_id: event.id });
                sources = again.sources || sources;
            }
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

    /** Поля школы, которые saveSource требует при любой правке строки (меняется только то, что передано дополнительно). */
    const baseFields = source => ({
        event_id: event.id,
        source_id: source.id,
        version: source.version,
        school_name: source.school_name,
        school_key: source.school_key || '',
        grade: source.grade,
        teacher_name: source.teacher_name,
    });

    /* ФИО преподавателя правится на месте: ссылка и ключ остаются прежними. Enter — сохранить, Esc — отмена. */
    function editTeacher(row, id) {
        const source = sources.find(x => x.id === id);
        const box = row.querySelector('.es-teacher');
        if (!source || !box || box.querySelector('input')) { return; }

        box.classList.add('is-editing');
        box.innerHTML = `<input type="text" class="es-teacher-input" maxlength="255" aria-label="ФИО преподавателя" value="${esc(source.teacher_name)}">
            <button type="button" class="es-icon es-icon--ok" data-src="teacher-save" title="Сохранить" aria-label="Сохранить">${icoCheck(16)}</button>
            <button type="button" class="es-icon es-icon--no" data-src="teacher-cancel" title="Отмена" aria-label="Отмена">${icoX(14)}</button>`;
        const input = box.querySelector('input');
        input.focus();
        input.select();

        const save = () => act(() => api('saveSource', { ...baseFields(source), teacher_name: input.value }), 'ФИО преподавателя изменено');
        box.querySelector('[data-src="teacher-save"]').addEventListener('click', save);
        box.querySelector('[data-src="teacher-cancel"]').addEventListener('click', draw);
        input.addEventListener('keydown', (e) => {
            if ('Enter' === e.key) { e.preventDefault(); save(); }
            if ('Escape' === e.key) { e.stopPropagation(); draw(); }
        });
    }

    /* Выбор сеансов ссылки: меню как у выбора учеников доп. занятия (prof-ctx-menu): первая строка «Все»,
       у каждого сеанса справа флажок; сохраняется при закрытии. «Все» (или ничего) — доступны все сеансы. */
    function pickSessions(btn, source) {
        const list = selectableSessions(sessions);
        if (!list.length) { toast('Сеансов пока нет', 'error'); return; }

        const chosen = new Set((source.session_ids || []).map(Number).filter(id => list.some(s => Number(s.id) === id)));
        const allChosen = !chosen.size;
        const row = (attrs, name) => `<label class="ctx-item ctx-item--multi"><span class="ctx-lbl">${name}</span><input type="checkbox" ${attrs}></label>`;
        const html = row(`data-es="all" ${allChosen ? 'checked' : ''}`, 'Все')
            + list.map(s => row(`data-es="sess" value="${s.id}" ${allChosen || chosen.has(Number(s.id)) ? 'checked' : ''}`,
                `${esc(fmtDayMonth(s.date))} · ${esc(s.time_start)} · ${esc(s.room_name || '')}`)).join('');

        openCtxMenuRaw(html, btn, () => {
            const checked = [...document.querySelectorAll('#profCtxMenu [data-es="sess"]:checked')].map(i => Number(i.value));
            const next = checked.length === list.length ? [] : checked.sort((a, b) => a - b);
            const prev = [...chosen].sort((a, b) => a - b);
            if (next.join(',') === prev.join(',')) { return; }
            act(() => api('saveSource', { ...baseFields(source), session_ids_set: 1, session_ids: next }), next.length ? 'Сеансы ссылки сохранены' : 'Ограничение сеансов снято');
        }, { alignRight: true, flip: true });

        const menu = document.getElementById('profCtxMenu');
        menu.classList.toggle('prof-ctx-menu--scroll', list.length > 12);
        const all = menu.querySelector('[data-es="all"]');
        const checks = [...menu.querySelectorAll('[data-es="sess"]')];
        all.addEventListener('change', () => { checks.forEach(c => { c.checked = all.checked; }); });
        checks.forEach(c => c.addEventListener('change', () => { all.checked = checks.every(x => x.checked); }));
    }

    /* Меню «⋯»: вниз и влево от кнопки, при нехватке места внизу — вверх и влево. */
    function openRowMenu(btn, source) {
        const id = source.id;
        const blocked = (Number(source.registered) || 0) > 0 || (Number(source.active_holds) || 0) > 0;
        const items = [];
        if (source.has_link || source.revoked) { items.push('<div class="ctx-item" data-act="reissue">Перевыпустить ссылку</div>'); }
        if (!source.is_active) { items.push('<div class="ctx-item" data-act="enable">Включить ссылку</div>'); }
        items.push(blocked
            ? '<div class="ctx-item is-disabled" data-act="blocked" title="По ссылке есть записи — удалить её нельзя">Удалить запись</div>'
            : '<div class="ctx-item danger" data-act="delete">Удалить запись</div>');
        openCtxMenuRaw(items.join(''), btn, null, { alignRight: true, flip: true });

        const bind = (name, handler) => {
            document.querySelector(`#profCtxMenu [data-act="${name}"]`)?.addEventListener('click', () => { closeCtxMenu(); handler(); });
        };
        bind('reissue', async () => {
            if (!await confirmDialog(REISSUE_TEXT, 'Перевыпустить', 'Не перевыпускать')) { return; }
            await act(async () => { await api('reissueSourceLink', { source_id: id }); }, 'Ссылка перевыпущена');
        });
        bind('enable', () => act(() => api('toggleSource', { source_id: id, active: 1 }), 'Ссылка включена'));
        bind('blocked', () => toast('По ссылке есть записи — удалить её нельзя.', 'error'));
        bind('delete', async () => {
            const text = `Запись «${source.school_name}», преподаватель ${source.teacher_name}, будет удалена вместе со ссылкой без возможности восстановления. Удаление предназначено для тестовых ссылок.`;
            if (!await confirmDialog(text, 'Удалить', 'Не удалять')) { return; }
            await act(async () => { await api('deleteSource', { source_id: id }); }, 'Запись удалена');
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
            const source = sources.find(x => x.id === id);
            if (!source) { return; }
            row.querySelector('[data-src="copy"]')?.addEventListener('click', async () => {
                const copied = await copyToClipboard(source.url);
                toast(copied ? 'Ссылка скопирована' : 'Не удалось скопировать', copied ? 'ok' : 'error');
            });
            row.querySelector('[data-src="sessions"]')?.addEventListener('click', e => pickSessions(e.currentTarget, source));
            row.querySelector('[data-src="menu"]')?.addEventListener('click', e => openRowMenu(e.currentTarget, source));
            row.querySelector('[data-src="edit-teacher"]')?.addEventListener('click', () => editTeacher(row, id));
        });
    }

    container.innerHTML = '<div class="es-empty">Загрузка…</div>';
    reload();
}
