/**
 * Сворачивание карточки фильтров на узком экране (тренажёр, каталог учебника).
 *
 * На мобильном карточка стоит над списком, и раскрытые группы заняли бы весь
 * первый экран — тело прячется под кнопку «Фильтры». Видимость решает CSS по
 * классу `is-open`; на десктопе кнопка скрыта, и класс ни на что не влияет.
 * UI-only, без AJAX.
 */

/**
 * @returns {void}
 */
export function initFiltersToggle() {
    document.querySelectorAll('.js-filters-toggle').forEach((btn) => {
        const card = btn.closest('.filters-side');
        if (!card) return;

        btn.addEventListener('click', () => {
            const open = card.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', String(open));
        });
    });
}
