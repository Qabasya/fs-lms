/**
 * Липкие сайдбары выше экрана: едут вместе со страницей в обе стороны.
 *
 * Обычный `sticky` прилипает верхом, и низ сайдбара выше экрана недостижим.
 * Здесь `top` следует за прокруткой в пределах [верх прижат … низ прижат]:
 * вниз сайдбар доезжает до своего конца и прилипает низом, вверх — до своего
 * начала и прилипает верхом. Позиционирование остаётся на CSS (миксин
 * fs-sticky), JS отдаёт только число в `--fs-sticky-top`.
 *
 * Отсчёт идёт от фактической позиции блока на прошлом кадре, а не от
 * накопленного `top`: пока блок не прилип (над ним шапка или он упёрся в низ
 * сетки), прокрутка двигает его сама, и накопленное значение разъезжалось бы
 * с реальным — сайдбар прыгал к краю, а на прокрутке вверх не отлипал.
 *
 * Страницы: тренажёр, учебник, статья, задание (`.js-scroll-sticky`).
 */

import { debounce } from '../../common/utils.js';
import { onScrollFrame } from '../modules/scroll-frame.js';

/** Класс, под которым `top` берётся из переменной. */
const ACTIVE_CLASS = 'is-scroll-sticky';

/** Зазор под сайдбаром, когда он прилип низом; ступень — как у миксина fs-sticky. */
const BOTTOM_GAP = 28;

/** Пауза перед перезамером: resize сыплется десятками событий подряд. */
const REMEASURE_DELAY = 100;

/**
 * @returns {void}
 */
export function initScrollSticky() {
    document.querySelectorAll('.js-scroll-sticky').forEach(bindScrollSticky);
}

/**
 * Ведёт один сайдбар.
 *
 * @param {HTMLElement} el Липкий блок.
 * @returns {void}
 */
function bindScrollSticky(el) {
    let baseTop = 0;
    let minTop  = 0;
    let lastTop = 0;
    let lastY   = window.scrollY;

    const apply = (top) => {
        el.style.setProperty('--fs-sticky-top', `${top}px`);
        lastTop = el.getBoundingClientRect().top;
        lastY   = window.scrollY;
    };

    const measure = () => {
        // Позицию снимаем до сброса класса: перезамер посреди прокрутки не
        // должен возвращать сайдбар к верху. Базовый отступ — уже без класса.
        const current = el.getBoundingClientRect().top;

        el.classList.remove(ACTIVE_CLASS);

        const styles = window.getComputedStyle(el);

        // Сайдбар статичен (узкий экран) — вести нечего.
        if (styles.position !== 'sticky') return;

        baseTop = parseFloat(styles.top) || 0;

        const hidden = el.offsetHeight - (window.innerHeight - baseTop - BOTTOM_GAP);

        // Помещается на экран целиком — хватает обычного sticky.
        if (hidden <= 0) return;

        minTop = baseTop - hidden;

        const top = clamp(current, minTop, baseTop);

        el.classList.add(ACTIVE_CLASS);
        apply(top);
    };

    const sync = () => {
        if (!el.classList.contains(ACTIVE_CLASS)) return;

        apply(clamp(lastTop - (window.scrollY - lastY), minTop, baseTop));
    };

    onScrollFrame(sync);

    // Высота меняется от сворачивания секций, подгрузки шрифтов и картинок.
    const remeasure = debounce(measure, REMEASURE_DELAY);

    if (window.ResizeObserver) new ResizeObserver(remeasure).observe(el);

    window.addEventListener('resize', remeasure);

    measure();
}

/**
 * Зажимает значение в границах.
 *
 * @param {number} value Значение.
 * @param {number} min   Нижняя граница.
 * @param {number} max   Верхняя граница.
 * @returns {number}
 */
function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}
