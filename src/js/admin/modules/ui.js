/**
 * @fileoverview Модуль инициализации UI компонентов плагина.
 * @description Автоматически обнаруживает и инициализирует все компоненты (модальные окна и другие UI элементы)
 *              из директории '../components'. Использует Webpack require.context для динамического импорта.
 * @requires webpack - Требуется для работы require.context
 */

/**
 * Объект для централизованной инициализации всех UI компонентов.
 * @namespace UI
 * @typedef {Object} UI
 */
export const UI = {
    /**
     * Инициализирует все компоненты из директории '../components'.
     * Автоматически находит все .js файлы, импортирует их и вызывает метод init(),
     * если компонент имеет такой метод.
     *
     * @memberof UI
     * @instance
     * @returns {void}
     *
     * @example
     * // Инициализация всех компонентов после загрузки DOM
     * jQuery(document).ready(() => {
     *     UI.init();
     * });
     *
     * @example
     * // Если компоненты нужно инициализировать после AJAX-загрузки контента
     * $.ajax({
     *     url: '/some-endpoint',
     *     success: function(response) {
     *         $('#container').html(response.html);
     *         UI.init(); // Повторная инициализация для новых компонентов
     *     }
     * });
     */
    init() {
        /**
         * Контекст для динамического импорта всех JavaScript файлов из директории '../components'
         * @type {__WebpackModuleApi.RequireContext}
         * @description require.context - это специальная функция Webpack, которая позволяет
         *              динамически импортировать несколько файлов из указанной директории.
         *              Параметры:
         *              - '../components' - путь к директории с компонентами
         *              - false - не искать рекурсивно во вложенных папках
         *              - /\.js$/ - регулярное выражение для фильтрации файлов (только .js)
         */
        const requireComponent = require.context('../components', false, /\.js$/);

        /**
         * Перебираем все найденные файлы компонентов
         * @param {string} fileName - Имя файла компонента (например, './Modal.js')
         */
        requireComponent.keys().forEach(fileName => {
            /**
             * Импортированный модуль компонента
             * @type {Object}
             * @description Модуль может экспортировать компонент в разных форматах:
             *              - как свойство Modal
             *              - как default экспорт
             *              - как первый попавшийся экспорт
             */
            const componentConfig = requireComponent(fileName);

            /**
             * Извлечение компонента из модуля
             * @type {Object|Function|*}
             * @description Пытаемся получить компонент в следующем порядке приоритета:
             *              1. Свойство Modal (если компонент экспортирован как Modal)
             *              2. Default экспорт (если используется export default)
             *              3. Первое свойство модуля (если компонент экспортирован без имени)
             */
            const component = componentConfig.Modal ||
                componentConfig.default ||
                componentConfig[Object.keys(componentConfig)[0]];

            /**
             * Проверяем, что компонент существует и имеет метод init
             * @type {boolean}
             */
            const hasInitMethod = component && typeof component.init === 'function';

            /**
             * Если компонент корректен - вызываем его метод init()
             * @description Используем условный вызов для предотвращения ошибок
             */
            if (hasInitMethod) {
                component.init();
            }
        });
    },
};

/**
 * @typedef {Object} ComponentModule
 * @property {Object} [Modal] - Экспортированный компонент с именем Modal
 * @property {Object} [default] - Экспортированный компонент по умолчанию
 * @property {Object} [key] - Динамические экспортированные компоненты
 */

/**
 * @typedef {Object} UIComponent
 * @property {Function} init - Метод инициализации компонента
 * @property {Function} [destroy] - Опциональный метод уничтожения компонента
 * @property {Function} [open] - Опциональный метод открытия компонента
 * @property {Function} [close] - Опциональный метод закрытия компонента
 */

/**
 * Функция-обёртка для безопасной инициализации UI в WordPress.
 * @function initUI
 * @description Проверяет наличие jQuery и DOM, затем инициализирует все компоненты.
 * @returns {void}
 *
 * @example
 * // В основном файле скрипта плагина
 * if (typeof window.fsLmsPlugin !== 'undefined') {
 *     initUI();
 * }
 */
export function initUI() {
    // Проверяем, что jQuery загружена
    if (typeof jQuery === 'undefined') {
        console.error('[UI] Ошибка: jQuery не загружена. Компоненты не будут инициализированы.');
        return;
    }

    // Проверяем, что DOM загружен
    const isDOMReady = document.readyState !== 'loading';

    if (isDOMReady) {
        // DOM уже загружен, инициализируем сразу
        UI.init();
    } else {
        // DOM ещё загружается, ждём события
        jQuery(document).ready(() => {
            UI.init();
        });
    }
}

/**
 * Функция для повторной инициализации компонентов после динамической загрузки контента.
 * @function reinitUI
 * @description Полезна при использовании AJAX-загрузки контента, когда новые компоненты
 *              добавляются в DOM после первоначальной инициализации.
 * @param {HTMLElement|jQuery|string} [context] - Контекст для частичной инициализации (опционально).
 * @returns {void}
 *
 * @example
 * // После AJAX-загрузки нового контента
 * $.ajax({
 *     url: '/load-content',
 *     success: function(html) {
 *         $('#dynamic-content').html(html);
 *         reinitUI('#dynamic-content'); // Инициализируем только новые компоненты
 *     }
 * });
 */
export function reinitUI(context) {
    if (typeof jQuery === 'undefined') {
        console.error('[UI] Ошибка: jQuery не загружена.');
        return;
    }

    // Если передан контекст - сохраняем текущие компоненты и инициализируем заново
    // Примечание: полная реинициализация всех компонентов может привести к дублированию обработчиков
    // Рекомендуется вместо этого использовать destroy() методы компонентов или инициализировать только новые

    if (context) {
        console.warn('[UI] Частичная реинициализация с контекстом требует доработки компонентов');
    }

    UI.init();
}