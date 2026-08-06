/**
 * Карточка задания внутри текста статьи: раскрытие условия.
 *
 * Разметку печатает партиал partials/article-task-card.php, высоту тела
 * анимирует CSS — здесь только класс состояния и aria. UI-only, без AJAX.
 */

/**
 * @returns {void}
 */
export function initArticleTaskCards() {
    const toggles = document.querySelectorAll('.js-article-task-toggle');
    if (toggles.length === 0) return;

    toggles.forEach(toggle => {
        toggle.addEventListener('click', () => {
            const card = toggle.closest('.js-article-task');
            if (!card) return;

            const open = card.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(open));
        });
    });
}