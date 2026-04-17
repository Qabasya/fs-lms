/**
 * @fileoverview Модуль управления таблицей постов (задач) с AJAX-фильтрацией и пагинацией.
 * @description Обеспечивает динамическую загрузку таблицы постов без перезагрузки страницы:
 *              фильтрация по статусам (All/Published/Draft/Trash), пагинация и поиск.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 */

import '../_types.js';

const $ = jQuery;

/**
 * Объект для управления таблицей постов с AJAX-загрузкой.
 * @namespace PostsTable
 */
export const PostsTable = {
    /**
     * Инициализирует модуль таблицы постов.
     * Проверяет наличие контейнера таблицы и навешивает обработчики событий
     * для фильтров, пагинации и поиска.
     * @memberof PostsTable
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     PostsTable.init();
     * });
     */
    init() {
        /**
         * Проверяем наличие контейнера таблицы на странице.
         * Если контейнер отсутствует — прекращаем инициализацию.
         */
        if (!$('.fs-posts-table-container').length) {
            return;
        }

        /**
         * Обработчик клика по ссылкам фильтрации статусов (All / Published / Draft / Trash).
         * @listens click.fs-posts-table-container .subsubsub a
         */
        $(document).on('click', '.fs-posts-table-container .subsubsub a', (e) => {
            e.preventDefault(); // Отменяем стандартный переход по ссылке
            const $link = $(e.currentTarget);
            this._load($link.closest('.fs-posts-table-container'), $link.attr('href'));
        });

        /**
         * Обработчик клика по ссылкам пагинации (вперед/назад, номера страниц).
         * @listens click.fs-posts-table-container .tablenav-pages a
         */
        $(document).on('click', '.fs-posts-table-container .tablenav-pages a', (e) => {
            e.preventDefault(); // Отменяем стандартный переход по ссылке
            const $link = $(e.currentTarget);
            this._load($link.closest('.fs-posts-table-container'), $link.attr('href'));
        });

        /**
         * Обработчик отправки формы поиска.
         * @listens submit.fs-posts-table-container #posts-filter
         */
        $(document).on('submit', '.fs-posts-table-container #posts-filter', (e) => {
            e.preventDefault(); // Отменяем стандартную отправку формы
            const $form = $(e.currentTarget);
            const $container = $form.closest('.fs-posts-table-container');

            /**
             * Значение поискового запроса.
             * @type {string}
             */
            const s = $form.find('[name="s"]').val() || '';

            /**
             * Выполняем загрузку с параметрами:
             * - s: поисковый запрос
             * - paged: сбрасываем на первую страницу
             * - post_status: сбрасываем фильтр статусов
             */
            this._load($container, null, { s, paged: 1, post_status: '' });
        });
    },

    /**
     * Загружает таблицу постов через AJAX.
     * Приватный метод, вызываемый при фильтрации, пагинации или поиске.
     *
     * @memberof PostsTable
     * @instance
     * @private
     * @param {JQuery} $container - jQuery-объект контейнера таблицы (.fs-posts-table-container).
     * @param {string|null} url - URL из ссылки (для фильтров и пагинации), содержащий параметры запроса.
     * @param {Object} [overrides] - Объект с параметрами, переопределяющими значения из URL.
     * @param {string} [overrides.post_status] - Статус постов для фильтрации (publish, draft, trash и т.д.).
     * @param {number} [overrides.paged] - Номер страницы пагинации.
     * @param {string} [overrides.s] - Поисковый запрос.
     * @returns {void}
     * @fires jQuery.ajax - AJAX-запрос к серверу для получения HTML таблицы
     */
    _load($container, url, overrides = {}) {
        /**
         * Извлекаем параметры из URL, если он передан.
         * Создаём новый URLSearchParams на основе строки запроса из URL.
         * @type {URLSearchParams}
         */
        const params = url
            ? new URLSearchParams(new URL(url, window.location.href).search)
            : new URLSearchParams();

        /**
         * Данные для отправки на сервер.
         * @type {Object}
         * @property {string} action - AJAX-действие для получения таблицы постов
         * @property {string} security - Nonce для проверки безопасности
         * @property {string} subject_key - Ключ предмета (из data-атрибута контейнера)
         * @property {string} tab - Вкладка (из data-атрибута контейнера)
         * @property {string} page_slug - Слаг страницы (из data-атрибута контейнера)
         * @property {string} post_status - Статус постов (из URL или overrides)
         * @property {number} paged - Номер страницы (из URL или overrides)
         * @property {string} s - Поисковый запрос (из URL или overrides)
         */
        const data = {
            action: fs_lms_vars.ajax_actions.getPostsTable,
            security: fs_lms_vars.subject_nonce,
            subject_key: $container.data('subject'),
            tab: $container.data('tab'),
            page_slug: $container.data('page'),
            post_status: overrides.post_status ?? (params.get('post_status') || ''),
            paged: overrides.paged ?? (params.get('paged') || 1),
            s: overrides.s ?? (params.get('s') || ''),
        };

        /**
         * Визуальный индикатор загрузки: уменьшаем прозрачность контейнера.
         */
        $container.css('opacity', '0.5');

        /**
         * Выполняем AJAX-запрос к серверу.
         * @param {string} url - URL обработчика AJAX WordPress
         * @param {string} type - HTTP метод запроса
         * @param {Object} data - Данные для отправки
         */
        $.ajax({
            url: fs_lms_vars.ajaxurl,
            type: 'POST',
            data,
            /**
             * Обработчик успешного ответа сервера.
             * @param {Object} response - Ответ сервера
             * @param {boolean} response.success - Флаг успешности операции
             * @param {Object} response.data - Данные ответа
             * @param {string} response.data.html - HTML-код таблицы постов
             */
            success(response) {
                // Восстанавливаем прозрачность контейнера
                $container.css('opacity', '');

                /**
                 * Если запрос выполнен успешно — обновляем содержимое контейнера.
                 */
                if (response.success) {
                    $container.html(response.data.html);

                    /**
                     * Переинициализируем inline-редактирование постов (WordPress native),
                     * если оно доступно в глобальной области.
                     * Это необходимо, так как после замены HTML события нужно навесить заново.
                     */
                    if (window.inlineEditPost) {
                        window.inlineEditPost.init();
                    }
                }
            },
            /**
             * Обработчик ошибки HTTP-запроса.
             */
            error() {
                // Восстанавливаем прозрачность контейнера при ошибке
                $container.css('opacity', '');
            },
        });
    },
};

