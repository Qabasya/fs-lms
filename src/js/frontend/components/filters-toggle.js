/**
 * Сворачивание карточки фильтров на узком экране (тренажёр, каталог учебника).
 *
 * На мобильном карточка стоит над списком, и раскрытые группы заняли бы весь
 * первый экран — тело прячется под кнопку «Фильтры». Видимость решает CSS по
 * классу `is-open`; на десктопе кнопка скрыта, и класс ни на что не влияет.
 * Раскрывает клик по всей шапке карточки, а не только по кнопке, — кроме
 * «Сбросить». UI-only, без AJAX.
 */

/**
 * @returns {void}
 */
export function initFiltersToggle() {
    document.querySelectorAll('.js-filters-toggle').forEach((btn) => {
        const card = btn.closest('.filters-side');
        const head = btn.closest('.filters-side-head');
        if (!card || !head) return;

        // Клик по кнопке всплывает сюда же — обработчик один.
        head.addEventListener('click', (event) => {
            // Кнопка скрыта — широкий экран, сворачивать нечего.
            if (null === btn.offsetParent || event.target.closest('.js-filters-clear')) return;

            const open = card.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', String(open));
        });
    });
}
