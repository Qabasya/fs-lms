/* ══════════════════════════════════════════════════════════════════════
   Вкладки курсов (sc-tabs) — общая обёртка «Моих курсов» ученика и страницы
   курса преподавателя (Tasks.md З4). Вкладки всегда в один ряд: не влезли —
   листаются, как карусель, стрелками по краям (и колесом/свайпом).
   Стрелки показываются, только когда есть куда листать (атрибут hidden).
   ══════════════════════════════════════════════════════════════════════ */

import { icoChevronLeft, icoChevronRight } from '../common/icons.js';

/** Разметка обёртки; сами вкладки рендерятся в `#${id}`. */
export function courseTabsShell(id) {
    return `<div class="sc-tabs-wrap">
        <button type="button" class="sc-tabs-nav prev" data-tabs-nav="-1" aria-label="Предыдущие курсы" hidden>${icoChevronLeft(16)}</button>
        <div class="sc-tabs" id="${id}"></div>
        <button type="button" class="sc-tabs-nav next" data-tabs-nav="1" aria-label="Следующие курсы" hidden>${icoChevronRight(16)}</button>
    </div>`;
}

/**
 * Оживляет карусель после отрисовки вкладок: стрелки, их видимость и
 * прокрутка к активной вкладке. Идемпотентно — обработчики вешаются один раз.
 *
 * @param {HTMLElement} tabs Контейнер `.sc-tabs`.
 */
export function syncCourseTabs(tabs) {
    const wrap = tabs?.closest('.sc-tabs-wrap');
    if (!wrap) { return; }
    const prev = wrap.querySelector('[data-tabs-nav="-1"]');
    const next = wrap.querySelector('[data-tabs-nav="1"]');

    const update = () => {
        const max = tabs.scrollWidth - tabs.clientWidth;
        prev.hidden = tabs.scrollLeft <= 1;
        next.hidden = tabs.scrollLeft >= max - 1;
    };

    if (!wrap.dataset.ready) {
        wrap.dataset.ready = '1';
        [prev, next].forEach(btn => btn.addEventListener('click', () => {
            tabs.scrollBy({ left: Number(btn.dataset.tabsNav) * tabs.clientWidth * 0.8, behavior: 'smooth' });
        }));
        tabs.addEventListener('scroll', update, { passive: true });
        // Экран кабинета монтируется скрытым (ширина 0) — стрелки пересчитываются,
        // когда вкладки получают реальный размер, и при любом ресайзе.
        new ResizeObserver(update).observe(tabs);
    }

    const active = tabs.querySelector('.sc-tab.on');
    if (active && (active.offsetLeft < tabs.scrollLeft || active.offsetLeft + active.offsetWidth > tabs.scrollLeft + tabs.clientWidth)) {
        tabs.scrollLeft = active.offsetLeft - (tabs.clientWidth - active.offsetWidth) / 2;
    }
    update();
}
