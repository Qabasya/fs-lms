const $ = jQuery;

import { showNotice } from '../modules/utils.js';

export const AuthSettings = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        // 1. Слушаем событие от твоего ToggleComponent (уже было)
        $(document).on('fs-toggle-changed', (e, data) => {
            // Проверяем, что это наш переключатель провайдера
            if (data.element.closest('.js-provider-toggle').length) {
                this.toggleFields(data.element, data.value);
            }
        });

        // 2. ДОБАВЛЯЕМ СЮДА: Логика клика по «глазику» для скрытия/показа пароля
        $(document).on('click', '.js-toggle-secret', function() {
            const $button = $(this);
            const $container = $button.closest('.fs-lms-secret-field');
            const $input = $container.find('input');
            const $icon = $button.find('.dashicons');

            if ($input.attr('type') === 'password') {
                // Показываем секрет
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                $button.attr('aria-label', 'Скрыть секретный ключ');
            } else {
                // Скрываем секрет
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                $button.attr('aria-label', 'Показать секретный ключ');
            }
        });
        $('#tab-2 form').on('submit', function(e) {
            e.preventDefault(); // Блокируем стандартную перезагрузку страницы

            const $form = $(this);
            const $submitButton = $form.find('#submit');

            // Визуально блокируем кнопку на время запроса
            $submitButton.prop('disabled', true).val('Сохранение...');

            // Отправляем данные на options.php в фоне
            $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                data: $form.serialize(),
                success: function() {
                    showNotice('Настройки успешно сохранены!', 'success', $form, {
                        autoDismiss: true,
                        autoDismissDelay: 2000
                    });
                },
                error: function() {
                    showNotice('Произошла ошибка при сохранении настроек.', 'error', $form, {
                        autoDismiss: false
                    });
                },
                complete: function() {
                    // Возвращаем кнопку в исходное состояние
                    $submitButton.prop('disabled', false).val('Сохранить изменения');
                }
            });
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