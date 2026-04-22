// В твоем modules/ui.js или отдельном badge.js
export const BadgeUI = {
    update($el, text, colorClass) {
        $el.text(text)
            .removeClass('is-green is-blue is-gray is-yellow is-red')
            .addClass(`is-${colorClass}`);
    }
};