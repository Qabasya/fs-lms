/**
 * Навешивает переключатель «Ответ» на карточки заданий.
 *
 * Условие карточки видно всегда; единственная кнопка «Ответ» (.js-answer-toggle)
 * раскрывает/скрывает панель с правильным ответом (.js-answer-panel).
 * Работает и для SSR-карточек, и для вставленных AJAX-ом.
 *
 * @param {Element} container - Корневой DOM-элемент, внутри которого искать карточки.
 */
export function bindCardTabs(container) {
    container.querySelectorAll('.task-card-row').forEach(card => {
        if (card._answerBound) return;
        card._answerBound = true;

        const toggle = card.querySelector('.js-answer-toggle');
        const panel  = card.querySelector('.js-answer-panel');
        if (!toggle || !panel) return;

        toggle.addEventListener('click', () => {
            const show = panel.hidden;
            panel.hidden = !show;
            toggle.classList.toggle('is-active', show);
            toggle.setAttribute('aria-expanded', String(show));
        });
    });
}