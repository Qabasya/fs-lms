/**
 * Список статей в сайдбаре «Всех заданий».
 *
 * Зеркало партиала `templates/frontend/partials/sidebar-articles.php`: сервер
 * рендерит первый экран, этот модуль перерисовывает список после смены фильтров.
 * UI-only, без AJAX — данные приходят из AllTasksPage.
 */

/**
 * Экранирует текст для вставки в разметку.
 *
 * @param {string} value Исходная строка.
 *
 * @returns {string}
 */
function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Перерисовывает список статей и прячет блок, когда статей нет.
 *
 * @param {Element}  block    Корень блока (.js-articles-block).
 * @param {Object[]} articles Статьи: title, url, excerpt.
 *
 * @returns {void}
 */
export function renderSidebarArticles(block, articles) {
    if (!block) return;

    const list  = block.querySelector('.js-articles-list');
    const items = Array.isArray(articles) ? articles : [];

    if (!list) return;

    list.innerHTML = items.map(article => `
        <li>
            <a href="${esc(article.url)}">
                <span class="fs-sidebar-article-title">${esc(article.title)}</span>
                ${article.excerpt ? `<span class="fs-sidebar-article-desc">${esc(article.excerpt)}</span>` : ''}
            </a>
        </li>
    `).join('');

    block.hidden = items.length === 0;
}