/* ══════════════════════════════════════════════════════════════════════
   Вкладки курсов (sc-tabs) — общая обёртка «Моих курсов» ученика и страницы
   курса преподавателя (Tasks.md З4). Вкладки всегда в один ряд: не влезли —
   листаются, как карусель, стрелками по краям (и колесом/свайпом).
   Стрелки показываются, только когда есть куда листать (атрибут hidden).
   ══════════════════════════════════════════════════════════════════════ */

import { icoChevronLeft, icoChevronRight } from '../common/icons.js';

/** Сдвиг указателя больше этого числа пикселей — перетаскивание ленты, а не клик по карточке. */
export const DRAG_THRESHOLD_PX = 6;

/** Доля ширины ленты, на которую стрелка листает вкладки курсов. */
const PAGE_STEP_RATIO = 0.8;

/**
 * Это перетаскивание, а не клик? Решает, глотать ли клик, который браузер шлёт после отпускания кнопки мыши.
 *
 * @param {number} dx Сдвиг указателя по горизонтали в пикселях (со знаком).
 */
export function isDrag(dx) {
    return Math.abs(dx) > DRAG_THRESHOLD_PX;
}

/**
 * На сколько пикселей листает стрелка: на одну карточку с промежутком либо, если карточка неизвестна, на долю ширины ленты.
 *
 * @param {number} viewport Ширина ленты.
 * @param {number} cardWidth Ширина карточки; 0 — листать «страницами».
 * @param {number} gap Промежуток между карточками.
 */
export function arrowStep(viewport, cardWidth, gap) {
    return cardWidth > 0 ? cardWidth + gap : viewport * PAGE_STEP_RATIO;
}

/**
 * Перетаскивание ленты мышью (касание листает нативно). Пока тянем, привязка к карточкам выключена
 * (класс `is-dragging`), после отпускания лента доводится до ближайшей. Клик, завершающий перетаскивание, глотается:
 * сдвиг не должен выбирать карточку под курсором.
 *
 * @param {HTMLElement} tabs Контейнер `.sc-tabs`.
 */
function enableMouseDrag(tabs) {
    let pointerId = null;
    let startX = 0;
    let startLeft = 0;
    let dragging = false;
    let swallowClick = false;

    tabs.addEventListener('pointerdown', ev => {
        if (ev.pointerType !== 'mouse' || ev.button !== 0) { return; }
        pointerId = ev.pointerId;
        startX = ev.clientX;
        startLeft = tabs.scrollLeft;
        dragging = false;
    });

    tabs.addEventListener('pointermove', ev => {
        if (ev.pointerId !== pointerId) { return; }
        const dx = ev.clientX - startX;
        if (!dragging && !isDrag(dx)) { return; }
        if (!dragging) {
            dragging = true;
            tabs.classList.add('is-dragging');
        }
        tabs.scrollLeft = startLeft - dx;
    });

    const finish = ev => {
        if (ev.pointerId !== pointerId) { return; }
        pointerId = null;
        if (!dragging) { return; }
        dragging = false;
        swallowClick = true;
        setTimeout(() => { swallowClick = false; }, 0);
        tabs.classList.remove('is-dragging');
    };
    tabs.addEventListener('pointerup', finish);
    tabs.addEventListener('pointercancel', finish);

    tabs.addEventListener('click', ev => {
        if (!swallowClick) { return; }
        ev.preventDefault();
        ev.stopPropagation();
    }, true);
}

/**
 * Разметка обёртки; сами карточки рендерятся в `#${id}`. Оболочка общая: вкладки курсов и экзаменов,
 * карточки сеансов экзамена — меняются подписи стрелок.
 *
 * @param {string} id Идентификатор ленты.
 * @param {{prevLabel?: string, nextLabel?: string}} [labels] Подписи стрелок для скринридеров.
 */
export function courseTabsShell(id, { prevLabel = 'Предыдущие курсы', nextLabel = 'Следующие курсы' } = {}) {
    return `<div class="sc-tabs-wrap">
        <button type="button" class="sc-tabs-nav prev" data-tabs-nav="-1" aria-label="${prevLabel}" hidden>${icoChevronLeft(16)}</button>
        <div class="sc-tabs" id="${id}"></div>
        <button type="button" class="sc-tabs-nav next" data-tabs-nav="1" aria-label="${nextLabel}" hidden>${icoChevronRight(16)}</button>
    </div>`;
}

/**
 * Оживляет карусель после отрисовки вкладок: стрелки, их видимость, перетаскивание мышью и
 * прокрутка к активной вкладке. Идемпотентно — обработчики вешаются один раз.
 *
 * @param {HTMLElement} tabs Контейнер `.sc-tabs`.
 * @param {{activeSelector?: string, stepByCard?: boolean}} [options] Селектор активной карточки
 *        (для вкладок — `.sc-tab.on`); `stepByCard` — стрелка листает на одну карточку, а не на 80% ширины.
 */
export function syncCourseTabs(tabs, { activeSelector = '.sc-tab.on', stepByCard = false } = {}) {
    const wrap = tabs?.closest('.sc-tabs-wrap');
    if (!wrap) { return; }
    const prev = wrap.querySelector('[data-tabs-nav="-1"]');
    const next = wrap.querySelector('[data-tabs-nav="1"]');

    // Кабинет может вызвать синхронизацию, пока экран скрыт (clientWidth = 0).
    // Выравниваем активную вкладку только после появления реальной ширины.
    wrap.dataset.alignPending = '1';
    wrap.dataset.stepByCard = stepByCard ? '1' : '';

    const update = () => {
        if (tabs.clientWidth > 0 && wrap.dataset.alignPending) {
            const active = tabs.querySelector(activeSelector);
            if (active === tabs.firstElementChild) {
                tabs.scrollTo({ left: 0, behavior: 'instant' });
            } else if (active && (active.offsetLeft < tabs.scrollLeft || active.offsetLeft + active.offsetWidth > tabs.scrollLeft + tabs.clientWidth)) {
                tabs.scrollTo({ left: Math.max(0, active.offsetLeft - (tabs.clientWidth - active.offsetWidth) / 2), behavior: 'instant' });
            }
            delete wrap.dataset.alignPending;
        }
        const max = tabs.scrollWidth - tabs.clientWidth;
        // Лента имеет внутренний паддинг 2px: scroll-snap вправе сдвинуть её на
        // эти пиксели, хотя первая карточка по-прежнему стоит у края.
        const atStart = tabs.scrollLeft <= (tabs.firstElementChild?.offsetLeft ?? 0) + 1;
        wrap.classList.toggle('is-at-start', atStart);
        prev.hidden = atStart || max <= 1;
        next.hidden = tabs.scrollLeft >= max - 1 || max <= 1;
    };

    if (!wrap.dataset.ready) {
        wrap.dataset.ready = '1';
        [prev, next].forEach(btn => btn.addEventListener('click', () => {
            const card = tabs.firstElementChild;
            const gap = parseFloat(getComputedStyle(tabs).columnGap) || 0;
            const step = arrowStep(tabs.clientWidth, wrap.dataset.stepByCard ? card?.offsetWidth ?? 0 : 0, gap);
            tabs.scrollBy({ left: Number(btn.dataset.tabsNav) * step, behavior: 'smooth' });
        }));
        enableMouseDrag(tabs);
        tabs.addEventListener('scroll', update, { passive: true });
        // Экран кабинета монтируется скрытым (ширина 0) — стрелки пересчитываются,
        // когда вкладки получают реальный размер, и при любом ресайзе.
        new ResizeObserver(update).observe(tabs);
    }

    update();
}
