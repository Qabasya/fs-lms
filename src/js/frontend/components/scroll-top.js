/**
 * Кнопка «наверх» на длинных публичных страницах.
 *
 * Появляется, когда пользователь ушёл вниз дальше SHOW_AFTER_SCREENS экранов,
 * и возвращает страницу к началу. UI-only, без AJAX.
 */

/** С какой глубины прокрутки (в высотах окна) показывать кнопку. */
const SHOW_AFTER_SCREENS = 1.5;

/**
 * @returns {void}
 */
export function initScrollTop() {
    const btn = document.querySelector('.js-to-top');
    if (!btn) return;

    let ticking = false;

    const sync = () => {
        ticking = false;
        btn.hidden = window.scrollY < window.innerHeight * SHOW_AFTER_SCREENS;
    };

    // Слушатель прокрутки срабатывает часто — реальную проверку делаем раз в кадр.
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(sync);
    }, { passive: true });

    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    sync();
}
