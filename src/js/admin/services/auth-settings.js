const $ = jQuery;

import { showNotice } from '../modules/utils.js';
import { HelpModal } from '../modals/help-modal.js';

export const AuthSettings = {

    init() {
        this.bindEvents();
        this._syncAllRequired();
    },

    bindEvents() {
        $(document).on('fs-toggle-changed', (e, data) => {
            if (data.element.closest('.js-provider-toggle').length) {
                this.toggleFields(data.element, data.value);
            }
        });

        $('#tab-2 form').on('submit', function(e) {
            e.preventDefault();

            if (!this.reportValidity()) {
                return;
            }

            const $form = $(this);
            const $submitButton = $form.find('#submit');

            $submitButton.prop('disabled', true).val('Сохранение...');

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
                    $submitButton.prop('disabled', false).val('Сохранить изменения');
                }
            });
        });
    },

    toggleFields($checkbox, isChecked) {
        let provider = $checkbox.data('provider') || $checkbox.closest('.js-provider-toggle').data('provider');

        if (!provider && $checkbox.attr('id')) {
            provider = $checkbox.attr('id').replace('_toggle', '');
        }

        if (!provider) return;

        const $targetRows = $(`.auth-fields-${provider}`);
        const $inputs = $targetRows.find('input[type="text"], input[type="password"]');

        if (isChecked) {
            $targetRows.removeClass('hidden');
            $inputs.prop('required', true);
        } else {
            $targetRows.addClass('hidden');
            $inputs.prop('required', false).removeClass('form-invalid');
        }
    },

    _syncAllRequired() {
        $('.fs-lms-auth-card__body').each((_, body) => {
            const $body = $(body);
            const isEnabled = !$body.hasClass('hidden');
            $body.find('input[type="text"], input[type="password"]').prop('required', isEnabled);
        });
    }
};
