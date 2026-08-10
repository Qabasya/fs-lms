/**
 * Оглавление статьи: подсветка текущего раздела, прогресс чтения, сворачивание.
 *
 * Список пунктов рисует PHP (якоря заголовкам проставляет ArticleContentService),
 * здесь — только поведение. UI-only, без AJAX.
 */

import { onScrollFrame } from '../modules/scroll-frame.js';

/** Отступ от верха окна, с которого заголовок считается текущим. */
const ACTIVE_OFFSET = 120;

/**
 * Ширина, ниже которой оглавление стартует свёрнутым: на узком экране оно
 * стоит над текстом и в развёрнутом виде отодвигает статью на экран вниз.
 * Медиазапросом, а не числом в px: ступень $bp-md задана в rem, и при системном
 * размере шрифта ≠ 16px сравнение с innerWidth разъехалось бы с вёрсткой
 * (components/article/_shell.scss).
 */
const COLLAPSE_QUERY = '(max-width: 56.25rem)';

/**
 * @returns {void}
 */
export function initArticleToc() {
    const toc   = document.querySelector('.js-article-toc');
    const prose = document.querySelector('.js-article-prose');
    if (!toc || !prose) return;

    const links = Array.from(toc.querySelectorAll('.js-article-toc-link'));
    if (links.length === 0) return;

    const progress = toc.querySelector('.js-article-progress');
    const toggle   = toc.querySelector('.js-article-toc-toggle');

    // Заголовок берём по якорю пункта: так список и статья не разъезжаются,
    // даже если контент отдал заголовки в другом порядке.
    const headings = links.map(link => findHeading(link.hash.slice(1)));

    // Геометрия меняется только на resize и подгрузке картинок, а не на каждом
    // кадре прокрутки — держим её замерами, а не чтением DOM в sync().
    let offsets    = [];
    let proseTop   = 0;
    let proseHeight = 0;

    const measure = () => {
        const scrollY = window.scrollY;

        offsets     = headings.map(heading => (heading ? heading.getBoundingClientRect().top + scrollY : Infinity));
        proseTop    = prose.getBoundingClientRect().top + scrollY;
        proseHeight = prose.offsetHeight;
    };

    const sync = () => {
        const edge = window.scrollY + ACTIVE_OFFSET;
        let active = 0;

        offsets.forEach((top, index) => {
            if (top <= edge) active = index;
        });

        links.forEach((link, index) => link.classList.toggle('is-active', index === active));

        if (progress) {
            const read = window.scrollY + window.innerHeight - proseTop;

            progress.value = proseHeight > 0 ? Math.min(100, Math.max(0, (read / proseHeight) * 100)) : 0;
        }
    };

    const remeasure = () => {
        measure();
        sync();
    };

    onScrollFrame(sync);

    window.addEventListener('resize', remeasure);

    // Картинки статьи догружаются после DOMContentLoaded и двигают всю геометрию;
    // на узком экране её двигает ещё и сворачивание оглавления над текстом.
    if (window.ResizeObserver) new ResizeObserver(remeasure).observe(prose);

    if (toggle) {
        setCollapsed(toc, toggle, window.matchMedia(COLLAPSE_QUERY).matches);

        toggle.addEventListener('click', () => {
            setCollapsed(toc, toggle, !toc.classList.contains('is-collapsed'));
            remeasure();
        });
    }

    measure();
    sync();
}

/**
 * Ищет заголовок по фрагменту ссылки.
 *
 * Кириллический якорь браузер отдаёт из hash percent-encoded, а в атрибуте id
 * он лежит как есть — поэтому пробуем оба написания.
 *
 * @param {string} hash Фрагмент ссылки без решётки.
 * @returns {HTMLElement|null}
 */
function findHeading(hash) {
    if (!hash) return null;

    let decoded = hash;

    try {
        decoded = decodeURIComponent(hash);
    } catch {
        // Битая escape-последовательность в якоре: ищем по исходной строке.
    }

    return document.getElementById(decoded) || document.getElementById(hash);
}

/**
 * Переключает свёрнутое состояние оглавления.
 *
 * @param {HTMLElement} toc       Корень оглавления.
 * @param {HTMLElement} toggle    Кнопка сворачивания.
 * @param {boolean}     collapsed Свернуть.
 * @returns {void}
 */
function setCollapsed(toc, toggle, collapsed) {
    toc.classList.toggle('is-collapsed', collapsed);
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Развернуть' : 'Свернуть');
}
