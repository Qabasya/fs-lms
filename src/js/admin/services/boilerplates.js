/**
 * @fileoverview Модуль управления шаблонами (boilerplates) для плагина FS-LMS.
 */

import '../_types.js';
import { ConfirmModal } from '../components/confirm-modal.js';
import { showNotice } from '../modules/utils.js';

const $ = jQuery;



export const Boilerplates = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        const $form = $('#fs-lms-boilerplate-form');

        if ($form.length) {
            $form.on('submit', (e) => {
                e.preventDefault();
                this.save($form);
            });
        }

        $('body').on('click', '.delete-boilerplate-link', (e) => {
            e.preventDefault();
            ConfirmModal.confirm({
                title: 'Удаление шаблона',
                message: 'Вы уверены, что хотите удалить этот шаблон?',
                confirmText: 'Удалить',
                cancelText: 'Отмена',
            }).then(() => this.deleteBoilerplate($(e.currentTarget)));
        });
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

    deleteBoilerplate($el) {
        const params = new URLSearchParams(window.location.search);
        const data = {
            action: fs_lms_vars.ajax_actions.deleteBoilerplate,
            nonce: $('#nonce').val(),
            uid: $el.data('uid'),
            subject_key: params.get('subject'),
            term_slug: params.get('term'),
        };

        $.post(fs_lms_vars.ajaxurl, data, (response) => {
            if (response.success) {
                $el.closest('tr')
                    .css('background', '#ff8d8d')
                    .fadeOut(400, function () {
                        $(this).remove();
                    });
            } else {
                alert('Ошибка: ' + (response.data?.message || response.data || 'Неизвестная ошибка'));
            }
        });
    },
};