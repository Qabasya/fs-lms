/* Пикер направления/группы/ученика (kp-btn с чипом + контекстное меню) — общий
   паттерн T12.8, используется в журнале, КТП и сводке. Раньше разметка и меню
   были продублированы в каждом экране. Любой другой выбор из списка — тот же вид:
   optionPickerBtnHtml + openOptionPicker (без чипа). Родной select в кабинете не нужен. */

import { esc, shortName, initials, chipBg, chipHtml, avatarUrl, groupSubjectKey, avaColor, openCtxMenu } from './utils.js';
import { icoCaret } from '../common/icons.js';

const CARET = icoCaret(12, 'kp-caret');

/** Цвет чипа группы: по её предмету; группы вне сайдбара (офис) — по subject_key из ответа. */
function groupChipBg(group) {
    return chipBg(group.subject_key || groupSubjectKey(group.id));
}

/** Кнопка выбора направления (предмета): чип цвета предмета + название. */
export function subjectPickerBtnHtml(subject, btnId) {
    return `<button type="button" class="kp-btn" id="${btnId}">
        ${chipHtml('kp-chip', subject.key, subject.name)}
        <span class="kp-txt">${esc(subject.name)}</span>
        ${CARET}
    </button>`;
}

/** Меню выбора направления; onPick получает ключ предмета (только при смене). */
export function openSubjectPicker(anchor, subjects, currentKey, onPick) {
    if (!anchor) return;
    openCtxMenu(
        anchor,
        subjects.map(s => ({
            v: s.key,
            label: s.name,
            active: s.key === currentKey,
            swatchClass: chipBg(s.key),
            swatchImg: avatarUrl(s.key),
            chip: shortName(s.name),
        })),
        v => { if (v !== currentKey) onPick(v); }
    );
}

/** Кнопка выбора группы (чип с цветом группы + «Название · предмет»). */
export function groupPickerBtnHtml(group, btnId) {
    return `<button type="button" class="kp-btn" id="${btnId}">
        ${chipHtml('kp-chip', group.subject_key || groupSubjectKey(group.id), group.name, group.id, group.avatar)}
        <span class="kp-txt">${esc(group.name)} · ${esc(group.subject)}</span>
        ${CARET}
    </button>`;
}

/**
 * Кнопка выбора из короткого списка значений (тип работы, статус, кабинет…): тот же `kp-btn`,
 * что у выбора группы, но без чипа. Родной select в кабинете не используется (src/js/CLAUDE.md).
 *
 * @param {string} text      Подпись выбранного значения
 * @param {string} btnId     id кнопки (по нему экран вешает клик)
 * @param {string} [ariaLabel] Название выбора для скринридера («Тип работы»)
 */
export function optionPickerBtnHtml(text, btnId, ariaLabel = '') {
    return `<button type="button" class="kp-btn" id="${btnId}"${ariaLabel ? ` aria-label="${esc(ariaLabel)}"` : ''}>
        <span class="kp-txt">${esc(text)}</span>
        ${CARET}
    </button>`;
}

/**
 * Меню выбора из списка значений под якорем; onPick получает `value` (только при смене).
 * Пустая строка — полноценное значение («Все типы»).
 *
 * @param {HTMLElement}                          anchor
 * @param {Array<{value: string, label: string}>} options
 * @param {string}                               currentValue
 * @param {(value: string) => void}              onPick
 */
export function openOptionPicker(anchor, options, currentValue, onPick) {
    if (!anchor) return;
    openCtxMenu(
        anchor,
        options.map(o => ({ v: o.value, label: o.label, active: o.value === currentValue })),
        v => { if (v !== currentValue) onPick(v); }
    );
}

/** Кнопка выбора ученика (чип-инициалы); без ростера — задизейблена. */
export function studentPickerBtnHtml(student, roster, btnId) {
    const hasRoster = roster && roster.length;
    return `<button type="button" class="kp-btn" id="${btnId}" ${hasRoster ? '' : 'disabled'}>
        ${student ? `<span class="kp-chip" style="background:${avaColor(roster, student.person_id)}">${initials(student.name)}</span>` : ''}
        <span class="kp-txt">${student ? esc(student.name) : '— нет активных учеников —'}</span>
        ${hasRoster ? CARET : ''}
    </button>`;
}

/**
 * Меню выбора группы под якорем; onPick получает числовой id (только при смене).
 * extra — доп. псевдо-пункты; каждый: {v, label, swatch, chip, first?}. По умолчанию добавляются
 * в конец списка (НБ-9: «Индивидуальные занятия», sentinel-id -1); с `first: true` встают в самое
 * начало — так стоит «Все группы» (фильтр «Работ»): сначала «все», потом конкретные группы.
 */
export function openGroupPicker(anchor, groups, currentId, onPick, extra = []) {
    if (!anchor) return;
    const items = groups.map(g => ({
        v: String(g.id),
        label: `${g.name} · ${g.subject}`,
        active: g.id === currentId,
        swatchClass: groupChipBg(g),
        swatchImg: avatarUrl(g.subject_key || groupSubjectKey(g.id), g.id, g.avatar),
        chip: shortName(g.name),
    }));
    const extraItem = e => {
        const { first, ...item } = e;
        return { ...item, active: String(e.v) === String(currentId) };
    };
    items.unshift(...extra.filter(e => e.first).map(extraItem));
    items.push(...extra.filter(e => !e.first).map(extraItem));
    openCtxMenu(
        anchor,
        items,
        v => {
            const id = parseInt(v, 10);
            if (id !== currentId) onPick(id);
        }
    );
}

/** Меню выбора ученика из ростера; onPick получает person_id (только при смене). */
export function openStudentPicker(anchor, roster, currentId, onPick) {
    if (!anchor || !roster.length) return;
    openCtxMenu(
        anchor,
        roster.map(s => ({
            v: String(s.person_id),
            label: s.name,
            active: s.person_id === currentId,
            swatch: avaColor(roster, s.person_id),
            chip: initials(s.name),
        })),
        v => {
            const id = parseInt(v, 10);
            if (id !== currentId) onPick(id);
        }
    );
}
