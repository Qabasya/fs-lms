/**
 * Навешивает переключатель ответа на карточки заданий.
 *
 * Одна и та же пара «кнопка + панель» на обеих публичных страницах: карточка
 * списка на «Всех заданиях» и карточка одного задания. Панель ищется по
 * aria-controls, поэтому обёртка карточки роли не играет.
 * Работает и для SSR-разметки, и для вставленной AJAX-ом.
 *
 * @param {Element} container - Корневой DOM-элемент, внутри которого искать кнопки.
 */
export function bindAnswerToggle(container) {
    if (!container) return;

    container.querySelectorAll('.js-answer-toggle').forEach(toggle => {
        if (toggle._answerBound) return;
        toggle._answerBound = true;

        const panelId = toggle.getAttribute('aria-controls');
        const panel   = panelId ? document.getElementById(panelId) : null;
        if (!panel) return;

        toggle.addEventListener('click', () => {
            const show = panel.hidden;

            panel.hidden = !show;
            toggle.classList.toggle('is-active', show);
            toggle.setAttribute('aria-expanded', String(show));
            toggle.textContent = show ? 'Скрыть ответ' : 'Показать ответ';
        });
    });
}
