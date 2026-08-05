/**
 * Оглавление статьи: подсветка текущего раздела, прогресс чтения, сворачивание.
 *
 * Список пунктов рисует PHP (якоря заголовкам проставляет ArticleContentService),
 * здесь — только поведение. UI-only, без AJAX.
 */

/** Отступ от верха окна, с которого заголовок считается текущим. */
const ACTIVE_OFFSET = 120;

/**
 * Ширина, ниже которой оглавление стартует свёрнутым: на узком экране оно
 * стоит над текстом и в развёрнутом виде отодвигает статью на экран вниз.
 * Ступень обязана совпадать с медиазапросом в components/article/_toc.scss.
 */
const COLLAPSE_BELOW = 900;

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

    let ticking = false;

    const sync = () => {
        ticking = false;

        const edge = window.scrollY + ACTIVE_OFFSET;
        let active = 0;

        headings.forEach((heading, index) => {
            if (heading && heading.getBoundingClientRect().top + window.scrollY <= edge) active = index;
        });

        links.forEach((link, index) => link.classList.toggle('is-active', index === active));

        if (progress) {
            const scrollable = document.documentElement.scrollHeight - window.innerHeight;
            progress.value = scrollable > 0 ? Math.min(100, (window.scrollY / scrollable) * 100) : 0;
        }
    };

    // Слушатель прокрутки срабатывает часто — реальную проверку делаем раз в кадр.
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(sync);
    }, { passive: true });

    if (toggle) {
        setCollapsed(toc, toggle, window.innerWidth <= COLLAPSE_BELOW);

        toggle.addEventListener('click', () => {
            setCollapsed(toc, toggle, !toc.classList.contains('is-collapsed'));
        });
    }

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