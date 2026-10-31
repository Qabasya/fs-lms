/** Общие элементы кастомизации главной: плитки, перестановка секций и выбор замены. */
import { esc, openCtxMenu } from './utils.js';
import { icoCalendar, icoCheck, icoAlert, icoStar, icoMinus, icoPlus, icoSwap, icoArrowUp, icoArrowDown } from '../common/icons.js';
import { addTile, removeTile, replaceTile, shiftItem, MAX_TILES } from './learner-home-tiles.js';

export function arrowsHtml() {
    return `<span class="prof-home-arrows"><button type="button" data-home-shift="-1" aria-label="Выше">${icoArrowUp(14)}</button><button type="button" data-home-shift="1" aria-label="Ниже">${icoArrowDown(14)}</button></span>`;
}

export function wireSortable(container, selector, onDrop, { grid = false } = {}) {
    // На тач-экранах перетаскивание не включаем: оно спорит с прокруткой — там работают стрелки «выше / ниже».
    if (!container || window.matchMedia('(pointer: coarse)').matches) { return; }
    const items = () => [...container.querySelectorAll(`:scope > ${selector}`)];
    let dragged = null;

    items().forEach(item => {
        item.draggable = true;
        item.addEventListener('dragstart', (e) => {
            if (e.target !== item) { return; }
            e.stopPropagation(); // вложенный сортируемый список (плитки в секции) не должен запускать перетаскивание секции
            dragged = item;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
            requestAnimationFrame(() => item.classList.add('is-dragging'));
        });
        item.addEventListener('dragend', (e) => {
            if (e.target !== item) { return; }
            e.stopPropagation();
            item.classList.remove('is-dragging');
            dragged = null;
            onDrop(items());
        });
    });

    container.addEventListener('dragover', (e) => {
        if (!dragged) { return; }
        e.preventDefault();
        e.stopPropagation();
        const target = e.target.closest(selector);
        if (!target || target === dragged || target.parentElement !== container) { return; }

        const rect = target.getBoundingClientRect();
        const there = dragged.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING;
        const sameRow = grid && Math.abs(rect.top - dragged.getBoundingClientRect().top) < rect.height / 2;
        const pointer = sameRow ? e.clientX : e.clientY;
        const middle = sameRow ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
        if (there ? pointer > middle : pointer < middle) {
            moveWithShift(items(), () => container.insertBefore(dragged, there ? target.nextSibling : target));
        }
    });
    container.addEventListener('drop', (e) => e.preventDefault());
}

/** Выполняет перестановку и плавно сдвигает затронутые элементы со старого места на новое (FLIP). */
function moveWithShift(list, mutate) {
    const before = new Map(list.map(el => [el, el.getBoundingClientRect()]));
    mutate();
    list.forEach(el => {
        const was = before.get(el);
        const now = el.getBoundingClientRect();
        const dx = was.left - now.left;
        const dy = was.top - now.top;
        if ((dx || dy) && !el.classList.contains('is-dragging')) {
            el.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: 200, easing: 'ease-out' });
        }
    });
}

export function homeTile(label, val, color, ico, href = '', opts = {}) {
    const remove = opts.removable ? `<button type="button" class="prof-home-minus" data-home-remove="${esc(opts.id)}" aria-label="Убрать плитку">${icoMinus(14)}</button><button type="button" class="prof-home-replace" data-home-replace="${esc(opts.id)}" aria-label="Заменить плитку" title="Заменить">${icoSwap(14)}</button>${arrowsHtml()}` : '';
    const icons = { cal: icoCalendar, check: icoCheck, alert: icoAlert, star: icoStar };
    const tag = href ? 'a' : 'div';
    const link = href ? ` href="${esc(href)}"` : '';
    const note = opts.note ? `<div class="st-delta">${esc(opts.note)}</div>` : '';
    const id = opts.removable ? ` data-tile-id="${esc(opts.id)}"` : '';
    return `<${tag} class="prof-stat-tile${href ? ' is-link' : ''}${opts.urgent ? ' is-urgent' : ''}"${link}${id}>${remove}
        <div class="st-top"><span class="st-ico" style="background:${color}1a;color:${color}">${icons[ico](16)}</span>${esc(label)}</div>
        <div class="st-val">${esc(val)}</div>${note}
    </${tag}>`;
}


export function editBarHtml() {
    return `<div class="prof-home-editbar"><span>Режим кастомизации: меняйте порядок секций и выбирайте плитки</span>
        <button type="button" class="prof-btn prof-btn-sm" data-home-reset>Сбросить</button>
        <button type="button" class="prof-btn prof-btn-sm prof-btn-primary" data-home-done>Готово</button></div>`;
}

export function tilesHtml(catalog, defaults, layout, editing, learner = true) {
    const ids = layout.tiles ?? defaults;
    const tiles = ids.map(id => catalog.find(t => t.id === id)).filter(Boolean).slice(0, MAX_TILES);
    const items = tiles.map(t => homeTile(t.label, t.value, t.color, t.ico, editing ? '' : (t.href || ''), { ...t, removable: editing })).join('');
    const room = editing && ids.length < MAX_TILES && catalog.some(t => !ids.includes(t.id));
    const add = room ? `<button type="button" class="prof-stat-tile prof-stat-tile--add" data-home-add aria-label="Добавить плитку">${icoPlus(26)}</button>` : '';
    return `<div class="prof-stat-tiles${learner ? ' prof-stat-tiles--learner' : ''}" data-home-section="tiles">${editing ? arrowsHtml() : ''}${items}${add}</div>`;
}

/** Сохранение всегда получает полную раскладку; замена сохраняет позицию плитки. */
export function wireHomeEditor(root, { layout, catalog, defaultTiles, defaultSections, save, redraw, done }) {
    const effectiveTiles = () => layout.tiles ?? defaultTiles;
    const commit = change => { Object.assign(layout, change); save(); redraw(); };
    root.querySelector('.prof-sched-card .prof-card-head')?.insertAdjacentHTML('beforeend', arrowsHtml());
    root.querySelectorAll('[data-home-shift]').forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        const tile = btn.closest('[data-tile-id]');
        const delta = Number(btn.dataset.homeShift);
        commit(tile ? { tiles: shiftItem(effectiveTiles(), tile.dataset.tileId, delta) }
            : { sections: shiftItem(layout.sections, btn.closest('[data-home-section]').dataset.homeSection, delta) });
    }));
    wireSortable(root.querySelector('.prof-dash-grid2'), '[data-home-section]', order => {
        const sections = order.map(el => el.dataset.homeSection);
        if (sections.join() !== layout.sections.join()) commit({ sections });
    });
    wireSortable(root.querySelector('.prof-stat-tiles[data-home-section]'), '.prof-stat-tile[data-tile-id]', order => {
        // Плитки, временно недоступные (например, летний рейтинг), сохраняют свою позицию в настройке.
        const visible = order.map(el => el.dataset.tileId);
        let i = 0;
        const tiles = effectiveTiles().map(id => visible.includes(id) ? visible[i++] : id);
        if (tiles.join() !== effectiveTiles().join()) commit({ tiles });
    }, { grid: true });
    root.querySelectorAll('[data-home-remove]').forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        commit({ tiles: removeTile(effectiveTiles(), btn.dataset.homeRemove) });
    }));
    const choices = () => catalog.filter(t => !effectiveTiles().includes(t.id)).map(t => ({ v: t.id, label: t.label.replace(/\u00A0/g, ' ') }));
    root.querySelector('[data-home-add]')?.addEventListener('click', e => {
        openCtxMenu(e.currentTarget, choices(), id => commit({ tiles: addTile(effectiveTiles(), id) }));
    });
    root.querySelectorAll('[data-home-replace]').forEach(btn => {
        btn.disabled = choices().length === 0;
        btn.addEventListener('click', e => {
            e.stopPropagation();
            openCtxMenu(e.currentTarget, choices(), id => commit({ tiles: replaceTile(effectiveTiles(), btn.dataset.homeReplace, id) }));
        });
    });
    root.querySelector('[data-home-reset]')?.addEventListener('click', () => {
        commit({ sections: [...defaultSections], tiles: null });
        document.dispatchEvent(new CustomEvent('fs-profile-menu-reset'));
    });
    root.querySelector('[data-home-done]')?.addEventListener('click', () => { done(); document.dispatchEvent(new CustomEvent('fs-profile-menu-done')); });
}

/** Последовательная запись снимков: быстрые действия не могут перезаписать более новую раскладку старым ответом. */
export function createLayoutSaver(send, onError) {
    let pending = Promise.resolve();
    return layout => {
        const snapshot = Object.fromEntries(Object.entries(layout).map(([key, value]) => [key, Array.isArray(value) ? [...value] : value]));
        pending = pending.catch(() => {}).then(() => send(snapshot)).catch(onError);
        return pending;
    };
}
