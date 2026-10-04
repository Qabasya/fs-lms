/* ══════════════════════════════════════════════════════════════════════
   Переключатель ребёнка родителя — общий для экранов `learner-*`
   (главная, курсы, оценки, посещаемость, «Мои экзамены»).
   Хранит выбранного ребёнка в одном месте и сообщает экранам о смене.
   ══════════════════════════════════════════════════════════════════════ */

import { esc } from './utils.js';

let childId = null;
const listeners = [];

export function isParent() {
    return !!window.fsProfile?.readOnly;
}

/** Выбранный ребёнок (null — не выбирали: берётся первый). */
export function getChildId() {
    return childId;
}

/** Подписка на смену ребёнка; экран вызывает её один раз при создании. */
export function onChildChange(cb) {
    listeners.push(cb);
}

export function childName() {
    const children = window.fsProfile?.children || [];
    const cur = childId || (children[0] && children[0].personId);
    const c = children.find(x => String(x.personId) === String(cur));
    return c ? c.name : '';
}

/** Панель «Только просмотр / Ученик: …» — только у родителя. */
export function childBar() {
    const children = window.fsProfile?.children || [];
    if (!isParent() || children.length < 1) { return ''; }
    const cur = childId || (children[0] && children[0].personId);
    return `<div class="prof-child-bar">
        <span class="prof-chip">Только просмотр</span>
        <label class="prof-child-pick">Ученик:
            <select id="learnerChild">
                ${children.map(c => `<option value="${c.personId}" ${String(c.personId) === String(cur) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
            </select>
        </label>
    </div>`;
}

export function wireChild(root) {
    const sel = root.querySelector('#learnerChild');
    if (!sel) return;
    sel.addEventListener('change', () => {
        childId = sel.value;
        listeners.forEach(cb => cb(childId));
    });
}
