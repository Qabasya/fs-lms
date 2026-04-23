const $ = jQuery;

export const ToggleComponent = {
    init() {
        // Делегируем событие, чтобы работало и в динамических таблицах
        $(document).on('change', '.fs-toggle input[type="checkbox"]', function(e) {
            const $checkbox = $(this);
            const isChecked = $checkbox.is(':checked');
            const toggleName = $checkbox.attr('name');

            // Генерируем событие для внешних модулей
            $(document).trigger('fs-toggle-changed', {
                name: toggleName,
                value: isChecked,
                element: $checkbox
            });


            const $switch = $checkbox.next('.fs-toggle-switch');
            $switch.css('opacity', '0.5');


        });
    }
};