export const UI = {
    init() {
        // Контекст: ищем в папке ./components, не заходим в подпапки, берем файлы .js
        const requireComponent = require.context('../components', false, /\.js$/);

        requireComponent.keys().forEach(fileName => {
            const componentConfig = requireComponent(fileName);

            // Получаем имя компонента (например, из 'modal.js' -> 'Modal')
            const component = componentConfig.Modal || componentConfig.default || componentConfig[Object.keys(componentConfig)[0]];

            // Если у компонента есть метод init — запускаем его
            if (component && typeof component.init === 'function') {
                component.init();
            }
        });
    }
};