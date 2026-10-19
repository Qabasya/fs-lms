/**
 * Кнопка «наверх» на длинных публичных страницах.
 *
 * Появляется, когда пользователь ушёл вниз дальше SHOW_AFTER_SCREENS экранов,
 * и возвращает страницу к началу. UI-only, без AJAX.
 */

import { onScrollFrame } from '../../common/scroll-frame.js';
import { shouldShowToTop, scrollToTop } from '../../common/scroll-to-top.js';

/**
 * @returns {void}
 */
export function initScrollTop() {
    const btn = document.querySelector('.js-to-top');
    if (!btn) return;

    const sync = () => {
        btn.hidden = !shouldShowToTop(window.scrollY, window.innerHeight);
    };

    onScrollFrame(sync);

    btn.addEventListener('click', () => scrollToTop());

    sync();
}
