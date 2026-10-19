/**
 * Подписка на прокрутку с частотой не чаще кадра.
 *
 * Слушатель scroll срабатывает пачками, а реальная работа (замеры, классы)
 * нужна раз в кадр — иначе одна прокрутка даёт десятки лишних layout.
 */

/**
 * Вызывает callback не чаще одного раза за кадр анимации.
 *
 * @param {Function}           callback Что делать на прокрутке.
 * @param {Window|HTMLElement} target   Что слушаем (по умолчанию окно).
 * @param {boolean}            capture  Фаза перехвата: прокрутка вложенных элементов не всплывает,
 *                                      так её ловит контейнер (экраны кабинета в `#profStage`).
 * @returns {void}
 */
export function onScrollFrame(callback, target = window, capture = false) {
    let ticking = false;

    target.addEventListener('scroll', () => {
        if (ticking) return;

        ticking = true;
        window.requestAnimationFrame(() => {
            ticking = false;
            callback();
        });
    }, { passive: true, capture });
}
