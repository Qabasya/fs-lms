/**
 * Module: AuthSettings
 *
 * Управление настройками аутентификации в админ-панели.
 * Обеспечивает интерактивность на странице настроек: переключение провайдеров,
 * показ/скрытие секретных ключей и AJAX-сохранение формы.
 */
const $ = jQuery;

import { showNotice } from '../modules/utils.js';

export const AuthSettings = {

    /**
     * Инициализация модуля настроек аутентификации.
     * Запускает подписку на все необходимые DOM-события.
     */
    init() {
        this.bindEvents();
    },

    /**
     * Подписка на события: переключение провайдера, показ/скрытие пароля, AJAX-отправка формы.
     */
    bindEvents() {
        // 1. Обработка переключения провайдера (ToggleComponent)
        $(document).on('fs-toggle-changed', (e, data) => {
            // Проверяем, что событие исходит от переключателя провайдера
            if (data.element.closest('.js-provider-toggle').length) {
                this.toggleFields(data.element, data.value);
            }
        });

        // 2. Кнопка «глазик» для отображения/скрытия секретного ключа (API secret)
        $(document).on('click', '.js-toggle-secret', function() {
            const $button = $(this);
            const $container = $button.closest('.fs-lms-secret-field');
            const $input = $container.find('input');
            const $icon = $button.find('.dashicons');

            if ($input.attr('type') === 'password') {
                // Показываем секрет: меняем тип input на text
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                $button.attr('aria-label', 'Скрыть секретный ключ');
            } else {
                // Скрываем секрет: меняем тип input на password
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                $button.attr('aria-label', 'Показать секретный ключ');
            }
        });

        // 3. AJAX-отправка формы настроек (для вкладки tab-2)
        $('#tab-2 form').on('submit', function(e) {
            e.preventDefault(); // Блокируем стандартную перезагрузку страницы

            const $form = $(this);
            const $submitButton = $form.find('#submit');

            // Блокируем кнопку и показываем индикатор сохранения
            $submitButton.prop('disabled', true).val('Сохранение...');

            // AJAX-запрос к options.php (стандартный обработчик настроек WordPress)
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
     * Показывает или скрывает поля настроек для конкретного провайдера
     *
     * @param {jQuery} $checkbox  DOM-элемент переключателя (чекбокс)
     * @param {boolean} isChecked Состояние переключателя (включён/выключен)
     */
    toggleFields($checkbox, isChecked) {
        // Пытаемся определить идентификатор провайдера из data-атрибута или ID элемента
        let provider = $checkbox.data('provider') || $checkbox.closest('.js-provider-toggle').data('provider');

        if (!provider && $checkbox.attr('id')) {
            // Пример: 'google_toggle' -> 'google'
            provider = $checkbox.attr('id').replace('_toggle', '');
        }

        if (!provider) return;

        // Все поля, относящиеся к этому провайдеру, имеют класс .auth-fields-{provider}
        const $targetRows = $(`.auth-fields-${provider}`);

        if (isChecked) {
            $targetRows.removeClass('hidden');
        } else {
            $targetRows.addClass('hidden');
        }
    }
};