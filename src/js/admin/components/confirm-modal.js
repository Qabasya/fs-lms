import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

const ConfirmModal = {
    $modal: null,
    $body: null,
    _reject: null,

    init() {
        this.$body = $('body');
        this.$modal = $('#fs-lms-confirm-modal');

        if (!this.$modal.length) {
            console.warn('[ConfirmModal] Модалка не найдена в DOM. Проверьте подключение confirm-modal.php');
        }
    },

    confirm({ title = 'Подтвердите действие', message = '', confirmText = 'Подтвердить', cancelText = 'Отмена' } = {}) {
        this.$modal.find('.fs-lms-modal-title').text(title);
        this.$modal.find('.fs-lms-modal-message').text(message);
        this.$modal.find('.fs-lms-modal-confirm').text(confirmText);
        this.$modal.find('.fs-lms-modal-cancel').text(cancelText);

        openModal(this.$modal);

        return new Promise((resolve, reject) => {
            this._reject = reject;

            this.$modal.find('.fs-lms-modal-confirm')
                .off('click.confirm')
                .on('click.confirm', () => { this._close(); resolve(); });

            this.$modal.find('.fs-lms-modal-cancel, .fs-lms-modal-close, .fs-lms-modal-backdrop')
                .off('click.confirm')
                .on('click.confirm', () => { this._close(); reject(); });

            bindEsc('confirm', () => { this._close(); reject(); });
        });
    },

    _close() {
        closeModal(this.$modal);
        unbindEsc('confirm');
        this._reject = null;
    },
};

export { ConfirmModal };
