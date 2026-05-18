const $ = jQuery;

export const ToggleSecretComponent = {
    init() {
        $(document).on('click', '.js-toggle-secret', function () {
            const $button = $(this);
            const $input = $button.closest('.fs-lms-secret-field').find('input');
            const $icon = $button.find('.dashicons');
            const isHidden = $input.attr('type') === 'password';

            $input.attr('type', isHidden ? 'text' : 'password');
            $icon
                .toggleClass('dashicons-visibility', !isHidden)
                .toggleClass('dashicons-hidden', isHidden);
            $button.attr('aria-label', isHidden ? 'Скрыть секретный ключ' : 'Показать секретный ключ');
        });
    }
};