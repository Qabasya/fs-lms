/**
 * Подписка на прокрутку с частотой не чаще кадра.
 *
 * Слушатель scroll срабатывает пачками, а реальная работа (замеры, классы)
 * нужна раз в кадр — иначе одна прокрутка даёт десятки лишних layout.
 */

/**
 * Вызывает callback не чаще одного раза за кадр анимации.
 *
 * @param {Function} callback Что делать на прокрутке.
 * @returns {void}
 */
export function onScrollFrame(callback) {
    let ticking = false;

    window.addEventListener('scroll', () => {
        if (ticking) return;

        ticking = true;
        window.requestAnimationFrame(() => {
            ticking = false;
            callback();
        });
    }, { passive: true });
}
