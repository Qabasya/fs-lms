// В твоем modules/ui.js или отдельном badge.js
export const BadgeComponent = {
    init() {
        // Пока ничего не нужно инициализировать
        // Но метод есть для единообразия структуры компонентов
    },
    update($el, text, colorClass) {
        $el.text(text)
            .removeClass('is-green is-blue is-gray is-yellow is-red')
            .addClass(`is-${colorClass}`);
    }
};