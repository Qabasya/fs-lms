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
     * @param {Object}   filters              - Активные фильтры.
     * @param {string}   filters.search       - Поисковая строка.
     * @param {string[]} filters.task_types   - Слаги типов заданий.
     * @param {number}   offset               - Смещение (для infinite scroll).
     * @param {number}   perPage              - Количество заданий на страницу.
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

        (filters.task_types || []).forEach(t => body.append('task_types[]', t));

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