const $ = jQuery;

export const AuthSettings = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        // Слушаем событие от твоего ToggleComponent
        $(document).on('fs-toggle-changed', (e, data) => {
            // Проверяем, что это наш переключатель провайдера
            if (data.element.closest('.js-provider-toggle').length) {
                this.toggleFields(data.element, data.value);
            }
        });
    },

    /**
     * Показывает или скрывает поля настроек
     * @param {jQuery} $checkbox
     * @param {boolean} isChecked
     */
    toggleFields($checkbox, isChecked) {
        let provider = $checkbox.data('provider') || $checkbox.closest('.js-provider-toggle').data('provider');

        if (!provider && $checkbox.attr('id')) {
            provider = $checkbox.attr('id').replace('_toggle', '');
        }

        if (!provider) return;

        const $targetRows = $(`.auth-fields-${provider}`);

        if (isChecked) {
            $targetRows.removeClass('hidden');
        } else {
            $targetRows.addClass('hidden');
        }
    }
};