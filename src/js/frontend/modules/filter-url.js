/**
 * Фильтры сайдбара в адресе страницы: `filters[<taxonomy>][]=<term>`.
 *
 * Формат общий у тренажёра и учебника и совпадает с тем, что разбирает SSR
 * (`Inc\Services\Task\TaskFilterParser`): ссылка с фильтром открывает раздел
 * уже с отмеченной опцией, а обновление страницы сохраняет выбор.
 */

/** Префикс ключей фильтров в строке запроса. */
const PARAM = 'filters';

/**
 * Читает опции, отмеченные сервером.
 *
 * @param {ParentNode} root Корень страницы.
 * @returns {Object<string, string[]>} Таксономия → слаги терминов.
 */
export function readActiveFilters(root) {
    const selected = {};

    root.querySelectorAll('.js-filter-option.is-active').forEach(btn => {
        const key   = btn.dataset.filter;
        const value = btn.dataset.value;
        if (!key || !value) return;

        (selected[key] = selected[key] || []).push(value);
    });

    return selected;
}

/**
 * Приводит адрес к выбранным фильтрам, не трогая остальные параметры.
 * Снятый фильтр не должен вернуться из старой ссылки.
 *
 * @param {Iterable<[string, Iterable<string>]>} filters Пары «таксономия → слаги».
 * @returns {void}
 */
export function syncFilterUrl(filters) {
    const params = new URLSearchParams(window.location.search);

    [...params.keys()]
        .filter(key => key.startsWith(`${PARAM}[`))
        .forEach(key => params.delete(key));

    for (const [taxonomy, terms] of filters) {
        for (const term of terms) params.append(`${PARAM}[${taxonomy}][]`, term);
    }

    const query = params.toString();
    window.history.replaceState({}, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
}
