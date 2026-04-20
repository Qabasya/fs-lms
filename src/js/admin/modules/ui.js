/**
 * Модуль централизованной инициализации UI-компонентов.
 * @namespace UI
 */
export const UI = {
    /**
     * Находит и инициализирует все компоненты из '../components'.
     * @returns {void}
     */
    init() {
        // require.context: Webpack-specific API для динамического импорта
        // Параметры: (путь, рекурсия, фильтр)
        const requireComponent = require.context('../components', false, /\.js$/);

        requireComponent.keys().forEach(fileName => {
            const componentConfig = requireComponent(fileName);

            // Поддержка разных стилей экспорта: named, default, first-export
            const component = componentConfig.Modal ||
                componentConfig.default ||
                componentConfig[Object.keys(componentConfig)[0]];

            if (component && typeof component.init === 'function') {
                try {
                    component.init();
                } catch (err) {
                    console.error(`[UI] Failed to initialize component "${fileName}":`, err);
                }
            }
        });
    },
};

/**
 * Точка входа для инициализации UI.
 * Проверяет окружение и вызывает UI.init() после готовности DOM.
 * @returns {void}
 */
export function initUI() {
    if (typeof jQuery === 'undefined') {
        console.error('[UI] jQuery not loaded. Components skipped.');
        return;
    }

    const isDOMReady = document.readyState !== 'loading';

    if (isDOMReady) {
        UI.init();
    } else {
        jQuery(document).ready(() => UI.init());
    }
}

/**
 * Повторная инициализация компонентов после динамической подгрузки контента.
 * @param {HTMLElement|jQuery|string} [context] - Не используется в текущей реализации
 * @returns {void}
 */
export function reinitUI(context) {
    if (typeof jQuery === 'undefined') {
        console.error('[UI] jQuery not loaded.');
        return;
    }

    if (context) {
        console.warn('[UI] Context-based reinit not implemented. Full reinit called.');
    }

    // Предупреждение: полная реинициализация может дублировать обработчики событий
    UI.init();
}