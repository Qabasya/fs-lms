/**
 * Кнопка «Наверх»: общая логика сайта (прокрутка окна) и кабинета (прокрутка экрана).
 *
 * Здесь только решения «показать ли» и «как вернуть к началу»; подписка на прокрутку
 * и разметка кнопки — у потребителя (`frontend/components/scroll-top.js`, `profile/scroll-chrome.js`).
 */

/** С какой глубины прокрутки (в высотах видимой области) показывать кнопку. */
export const SHOW_AFTER_SCREENS = 1.5;

/**
 * @param {number} scrolled  Сколько уже прокручено, px.
 * @param {number} viewport  Высота видимой области, px.
 * @returns {boolean}
 */
export function shouldShowToTop(scrolled, viewport) {
    return scrolled >= viewport * SHOW_AFTER_SCREENS;
}

/**
 * Возвращает прокрутку к началу; без плавности при `prefers-reduced-motion`.
 *
 * @param {Window|HTMLElement} scroller Что прокручивать (по умолчанию окно).
 * @returns {void}
 */
export function scrollToTop(scroller = window) {
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    scroller.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
}
