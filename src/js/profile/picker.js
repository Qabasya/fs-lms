/* Пикер группы/ученика (kp-btn с чипом + контекстное меню) — общий паттерн
   T12.8, используется в журнале, КТП и сводке. Раньше разметка и меню были
   продублированы в каждом экране. */

import { esc, shortName, initials, groupColor, avaColor, openCtxMenu } from './utils.js';

const CARET = '<svg class="kp-caret" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5 6 8l3-3.5z" fill="currentColor"/></svg>';

/** Кнопка выбора группы (чип с цветом группы + «Название · предмет»). */
export function groupPickerBtnHtml(group, btnId) {
    return `<button type="button" class="kp-btn" id="${btnId}">
        <span class="kp-chip" style="background:${groupColor(group.id)}">${esc(shortName(group.name))}</span>
        <span class="kp-txt">${esc(group.name)} · ${esc(group.subject)}</span>
        ${CARET}
    </button>`;
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
 * extra — доп. псевдо-пункты (НБ-9: «Индивидуальные занятия», sentinel-id -1),
 * добавляются в конец списка; каждый: {v, label, swatch, chip}.
 */
export function openGroupPicker(anchor, groups, currentId, onPick, extra = []) {
    if (!anchor) return;
    const items = groups.map(g => ({
        v: String(g.id),
        label: `${g.name} · ${g.subject}`,
        active: g.id === currentId,
        swatch: groupColor(g.id),
        chip: shortName(g.name),
    }));
    extra.forEach(e => items.push({ ...e, active: String(e.v) === String(currentId) }));
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
