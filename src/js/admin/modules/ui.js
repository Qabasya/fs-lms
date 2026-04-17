export const UI = {
    init() {
        const requireComponent = require.context('../components', false, /\.js$/);

        requireComponent.keys().forEach(fileName => {
            const componentConfig = requireComponent(fileName);
            const component = componentConfig.Modal || componentConfig.default || componentConfig[Object.keys(componentConfig)[0]];

            if (component && typeof component.init === 'function') {
                component.init();
            }
        });
    },
};
