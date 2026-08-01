/**
 * AllTasksApi — единственный слой общения с сервером для страницы «Все задания».
 *
 * Не знает ничего о DOM. Возвращает Promise с нормализованным ответом.
 */
export class AllTasksApi {
    /**
     * @param {string} ajaxUrl    - URL WordPress AJAX-обработчика.
     * @param {string} ajaxAction - Имя action (snake_case).
     * @param {string} nonce      - Одноразовый токен безопасности.
     * @param {string} subjectKey - Ключ предмета.
     */
    constructor(ajaxUrl, ajaxAction, nonce, subjectKey) {
        this._url        = ajaxUrl;
        this._action     = ajaxAction;
        this._nonce      = nonce;
        this._subjectKey = subjectKey;
    }

    /**
     * Загружает список заданий с фильтрами и пагинацией.
     *
     * @param {Object} filters            - Активные фильтры.
     * @param {string} filters.search     - Поисковая строка.
     * @param {Object} filters.taxonomies - Карта {слаг таксономии: [слаги терминов]}.
     * @param {number} offset             - Смещение (для infinite scroll).
     * @param {number} perPage            - Количество заданий на страницу.
     *
     * @returns {Promise<{tasks: Object[], total: number, has_more: boolean}>}
     */
    fetch(filters, offset, perPage) {
        const body = new URLSearchParams({
            action:      this._action,
            security:    this._nonce,
            subject_key: this._subjectKey,
            offset:      offset,
            per_page:    perPage,
            search:      filters.search || '',
        });

        // Дженерик-контракт бэкенда: filters[<taxonomy_slug>][] = <term_slug>.
        Object.entries(filters.taxonomies || {}).forEach(([taxonomy, terms]) => {
            (terms || []).forEach(term => body.append(`filters[${taxonomy}][]`, term));
        });

        return fetch(this._url, { method: 'POST', body })
            .then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then(res => {
                if (!res.success) throw new Error(res.data?.message || 'Ошибка сервера');
                return res.data;
            });
    }
}