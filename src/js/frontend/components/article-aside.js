/**
 * Липкий сайдбар статьи: едет вместе с текстом, пока не покажется его низ.
 *
 * Сайдбар из трёх блоков (оглавление, курсы, тренажёр) выше экрана, и обычный
 * `sticky` прилипает верхом — нижние блоки остаются недостижимы. Здесь на
 * прокрутку вниз `top` уезжает в минус ровно на скрытую часть, на прокрутку
 * вверх возвращается к своему зазору. Позиционирование остаётся на CSS,
 * JS отдаёт только число в `--fs-aside-top`.
 */

import { debounce } from '../../common/utils.js';
import { onScrollFrame } from '../modules/scroll-frame.js';

/** Зазор под сайдбаром, когда он прилип низом; ступень — как у миксина fs-sticky. */
const BOTTOM_GAP = 28;

/** Пауза перед перезамером: resize сыплется десятками событий подряд. */
const REMEASURE_DELAY = 100;

/**
 * @returns {void}
 */
export function initArticleAside() {
    const aside = document.querySelector('.js-article-aside');
    if (!aside) return;

    let baseTop = 0;
    let minTop  = 0;
    let top     = null;
    let lastY   = window.scrollY;

    const measure = () => {
        // Базовый отступ читаем без класса: с ним `top` уже равен переменной.
        aside.classList.remove('is-scroll-sticky');

        const styles = window.getComputedStyle(aside);

        // Ниже $bp-md сайдбар статичен — вести нечего.
        if (styles.position !== 'sticky') return;

        baseTop = parseFloat(styles.top) || 0;

        const visible = window.innerHeight - baseTop - BOTTOM_GAP;
        const hidden  = aside.offsetHeight - visible;

        // Помещается на экран целиком — хватает обычного sticky.
        if (hidden <= 0) return;

        minTop = baseTop - hidden;
        top    = null === top ? baseTop : clamp(top, minTop, baseTop);
        lastY  = window.scrollY;

        aside.classList.add('is-scroll-sticky');
        aside.style.setProperty('--fs-aside-top', `${top}px`);
    };

    const sync = () => {
        if (!aside.classList.contains('is-scroll-sticky')) return;

        const y = window.scrollY;

        top   = clamp(top - (y - lastY), minTop, baseTop);
        lastY = y;

        aside.style.setProperty('--fs-aside-top', `${top}px`);
    };

    const remeasure = debounce(measure, REMEASURE_DELAY);

    onScrollFrame(sync);

    // Высота сайдбара меняется от сворачивания оглавления и подгрузки шрифтов.
    if (window.ResizeObserver) new ResizeObserver(remeasure).observe(aside);

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
