/**
 * Список статей в сайдбаре «Всех заданий».
 *
 * Зеркало партиала `templates/frontend/partials/sidebar-articles.php`: сервер
 * рендерит первый экран, этот модуль перерисовывает список после смены фильтров.
 * UI-only, без AJAX — данные приходят из AllTasksPage.
 */

import { escapeHtml as esc } from '../../common/utils.js';

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
                ${article.thumbnail
                    ? `<img class="fs-sidebar-article-thumb" src="${esc(article.thumbnail)}" alt="" loading="lazy" decoding="async" />`
                    : '<span class="fs-sidebar-article-thumb fs-sidebar-article-thumb--empty" aria-hidden="true"></span>'}
                <span class="fs-sidebar-article-text">
                    <span class="fs-sidebar-article-title">${esc(article.title)}</span>
                    <span class="fs-sidebar-article-go">Перейти</span>
                </span>
            </a>
        </li>
    `).join('');

    block.hidden = items.length === 0;
}