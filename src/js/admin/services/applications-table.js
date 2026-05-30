/**
 * @fileoverview Таблица заявок на вкладке "Заявки" (fs_lms_userlist).
 */

import { ConfirmModal } from '../components/confirm-modal.js';

const vars = window.fs_lms_vars;
const appVars = window.fs_lms_applications_vars;

const $ = jQuery;

export const ApplicationsTable = {
    init() {
        if (!appVars) {
            return;
        }

        ConfirmModal.init();
        this.bindEvents();
    },

    bindEvents() {
        $('body').on('click', '.fs-lms-copy-join', (e) => {
            e.preventDefault();
            this.copyJoinLink(e.currentTarget);
        });

        $('body').on('click', '.fs-lms-btn-trash', (e) => {
            e.preventDefault();
            this.confirmTrash(e.currentTarget);
        });

        $('body').on('click', '.fs-lms-btn-restore', (e) => {
            e.preventDefault();
            this.restore(e.currentTarget);
        });

        $('body').on('click', '.fs-lms-btn-delete', (e) => {
            e.preventDefault();
            this.confirmDelete(e.currentTarget);
        });
    },

    copyJoinLink(btn) {
        navigator.clipboard.writeText(btn.dataset.url).then(() => {
            const originalText = btn.textContent;

            btn.textContent = '✓ Скопировано';

            setTimeout(() => {
                btn.textContent = originalText;
            }, 2000);
        });
    },

    confirmTrash(btn) {
        ConfirmModal.confirm({
            title: 'Подтвердите действие',
            message: 'Переместить заявку в корзину?',
            confirmText: 'В корзину',
            cancelText: 'Отмена',
            size: 'sm',
            isDanger: true,
        })
            .then(() => this.moveToTrash(btn))
            .catch(() => {});
    },

    confirmDelete(btn) {
        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: 'Удалить заявку навсегда? Это действие нельзя отменить.',
            confirmText: 'Удалить',
            cancelText: 'Отмена',
            size: 'sm',
            isDanger: true,
        })
            .then(() => this.delete(btn))
            .catch(() => {});
    },

    moveToTrash(btn) {
        this.sendAction(
            btn,
            vars.ajax_actions.moveApplicationToTrash,
            btn.dataset.id
        );
    },

    restore(btn) {
        this.sendAction(
            btn,
            vars.ajax_actions.restoreApplicationFromTrash,
            btn.dataset.id
        );
    },

    delete(btn) {
        this.sendAction(
            btn,
            vars.ajax_actions.deleteApplication,
            btn.dataset.id
        );
    },

    sendAction(btn, action, applicationId) {
        btn.classList.add('disabled');

        $.post(fs_lms_vars.ajaxurl, {
            action,
            security: appVars.nonces.trash,
            application_id: applicationId,
        })
            .done((response) => {
                if (response.success) {
                    $(btn).closest('tr').fadeOut(300, function () {
                        $(this).remove();
                    });
                } else {
                    btn.classList.remove('disabled');

                    alert(
                        response.data?.message ||
                        response.data ||
                        'Произошла ошибка.'
                    );
                }
            })
            .fail(() => {
                btn.classList.remove('disabled');
                alert('Ошибка соединения с сервером.');
            });
    },
};