/**
 * @fileoverview Модуль управления шаблонами (boilerplates) для плагина FS-LMS.
 */

import '../_types.js';

const $ = jQuery;

/**
 * Выводит нативное уведомление WordPress.
 */
function showNotice(message, type, $container) {
    $container.find('.notice').remove();

    const $notice = $(`
        <div class="notice notice-${type} is-dismissible" style="margin-top: 10px; margin-bottom: 15px;">
            <p><strong>${type === 'success' ? 'Готово!' : 'Ошибка:'}</strong> ${message}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Закрыть уведомление</span>
            </button>
        </div>
    `);

    $notice.on('click', '.notice-dismiss', function() {
        $notice.fadeTo(100, 0, function() {
            $notice.slideUp(100, function() {
                $(this).remove();
            });
        });
    });

    $container.prepend($notice);

    if (type === 'success') {
        setTimeout(() => {
            $notice.find('.notice-dismiss').trigger('click');
        }, 5000);
    }
}

export const Boilerplates = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        const $form = $('#fs-lms-boilerplate-form');

        // Сохранение
        if ($form.length) {
            $form.on('submit', (e) => {
                e.preventDefault();
                this.save($form);
            });
        }

    },

    save($form) {
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        const $btn = $form.find('input[type="submit"]');
        const originalText = $btn.val();
        const data = $form.serialize();

        $btn.val('Сохранение...').prop('disabled', true);

        $.post(fs_lms_vars.ajaxurl, data)
            .done((response) => {
                if (response.success) {
                    showNotice('Шаблон успешно сохранен!', 'success', $form);

                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('action') === 'new' && response.data.uid) {
                        urlParams.set('action', 'edit');
                        urlParams.set('uid', response.data.uid);
                        window.location.search = urlParams.toString();
                    }
                } else {
                    const msg = response.data || 'Неизвестная ошибка';
                    showNotice(msg, 'error', $form);
                }
            })
            .fail(() => {
                showNotice('Ошибка сервера. Попробуйте позже.', 'error', $form);
            })
            .always(() => {
                $btn.val(originalText).prop('disabled', false);
            });
    },


};