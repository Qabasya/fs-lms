/**
 * Кнопка «наверх» на длинных публичных страницах.
 *
 * Появляется, когда пользователь ушёл вниз дальше SHOW_AFTER_SCREENS экранов,
 * и возвращает страницу к началу. UI-only, без AJAX.
 */

import { onScrollFrame } from '../modules/scroll-frame.js';

/** С какой глубины прокрутки (в высотах окна) показывать кнопку. */
const SHOW_AFTER_SCREENS = 1.5;

/**
 * @returns {void}
 */
export function initScrollTop() {
    const btn = document.querySelector('.js-to-top');
    if (!btn) return;

    const sync = () => {
        btn.hidden = window.scrollY < window.innerHeight * SHOW_AFTER_SCREENS;
    };

    onScrollFrame(sync);

    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    sync();
}
