/** Настройки относятся только к пунктам меню, не к правам доступа и отдельным блокам групп/курсов. */
import { esc } from './utils.js';
import { icoEye, icoEyeOff, icoArrowUp, icoArrowDown } from '../common/icons.js';
import { wireSortable } from './home-customization.js';
import { shiftItem } from './learner-home-tiles.js';

export function canHideMenu(role) { return ['lms_teacher', 'lms_office'].includes(role); }

export function orderedMenu(items, layout, editing = false, mayHide = false) {
    const keys = [...new Set([...(layout.menuOrder || []), ...items.map(item => item.key)])];
    return keys.map(key => items.find(item => item.key === key)).filter(Boolean)
        .map(item => ({...item, hidden: mayHide && (layout.menuHidden || []).includes(item.key)}))
        .filter(item => editing || !item.hidden);
}

/** Переставляет только пункты своего блока; порядок соседних блоков сохраняется. */
export function reorderMenu(order, blockKeys, nextKeys) {
    const full = [...new Set([...order, ...blockKeys])];
    let index = 0;
    return full.map(key => blockKeys.includes(key) ? nextKeys[index++] : key);
}

export function menuItemHtml(item, icon, editing, mayHide) {
    return `<div class="prof-nav-item${editing ? ' is-menu-editing' : ''}${item.hidden ? ' is-menu-hidden' : ''}" data-go="${esc(item.key)}" data-menu-key="${esc(item.key)}">
        <span class="ni-ico">${icon}</span><span class="prof-menu-title">${esc(item.label)}</span>
        ${editing && mayHide ? `<button type="button" class="prof-menu-eye" data-menu-eye="${esc(item.key)}" aria-label="${item.hidden ? 'Показать' : 'Скрыть'} пункт" title="${item.hidden ? 'Показать' : 'Скрыть'}">${item.hidden ? icoEyeOff(15) : icoEye(15)}</button>` : ''}
        ${editing ? `<span class="prof-menu-arrows"><button type="button" data-menu-shift="-1" aria-label="Выше">${icoArrowUp(13)}</button><button type="button" data-menu-shift="1" aria-label="Ниже">${icoArrowDown(13)}</button></span>` : ''}
    </div>`;
}

export function wireMenuEditor(root, {layout, editing, mayHide, commit}) {
    if (!editing || !root) return;
    root.querySelectorAll('[data-menu-eye]').forEach(button => button.addEventListener('click', event => {
        event.stopPropagation();
        if (!mayHide) return;
        const key = button.dataset.menuEye;
        const hidden = layout.menuHidden || [];
        commit({menuHidden: hidden.includes(key) ? hidden.filter(id => id !== key) : [...hidden, key]});
    }));
    root.querySelectorAll('[data-menu-shift]').forEach(button => button.addEventListener('click', event => {
        event.stopPropagation();
        const row = button.closest('[data-menu-key]');
        const container = row.closest('[data-menu-block]');
        const keys = [...container.querySelectorAll('[data-menu-key]')].map(node => node.dataset.menuKey);
        commit({menuOrder: reorderMenu(layout.menuOrder || [], keys, shiftItem(keys, row.dataset.menuKey, Number(button.dataset.menuShift)))});
    }));
    root.querySelectorAll('[data-menu-block]').forEach(container => wireSortable(container, '[data-menu-key]', order => {
        const keys = order.map(node => node.dataset.menuKey);
        commit({menuOrder: reorderMenu(layout.menuOrder || [], keys, keys)});
    }));
}
