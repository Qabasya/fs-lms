import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Утилита для управления модальным окном подтверждения действий.
 * @namespace ConfirmModal
 */
const ConfirmModal = {
    /** @type {JQuery} */
    $modal: null,
    /** @type {JQuery} */
    $body: null,

    /**
     * Инициализация модуля: кэширует DOM-элементы.
     * Вызывать один раз после отрисовки страницы.
     */
    init() {
        this.$body = $('body');
        this.$modal = $('#fs-lms-confirm-modal');

        if (!this.$modal.length) {
            console.warn('[ConfirmModal] Модалка не найдена в DOM. Проверьте подключение confirm-modal.php');
        }
    },

    /**
     * Открывает модалку и возвращает Promise.
     * @param {Object} [options] Параметры отображения
     * @param {string} [options.title='Подтвердите действие']
     * @param {string} [options.message='']
     * @param {string} [options.confirmText='Подтвердить']
     * @param {string} [options.cancelText='Отмена']
     * @returns {Promise<void>} Резолвится при подтверждении, реджектится при отмене/ESC
     */
    confirm({
                title = 'Подтвердите действие',
                message = '',
                confirmText = 'Подтвердить',
                cancelText = 'Отмена',
                size = 'md',
                isDanger = true
            } = {}) {

        const $content = this.$modal.find('.fs-lms-modal-content');
        $content.removeClass('fs-modal-sm fs-modal-md fs-modal-lg fs-modal-xl')
            .addClass(`fs-modal-${size}`);

        const $confirmBtn = this.$modal.find('.fs-lms-modal-confirm');
        $confirmBtn.removeClass('button-link-delete button-primary');
        if (isDanger) {
            $confirmBtn.addClass('button-link-delete');
        } else {
            $confirmBtn.addClass('button-primary');
        }

        this.$modal.find('.fs-lms-modal-title').text(title);
        this.$modal.find('.fs-lms-modal-message').text(message);
        $confirmBtn.text(confirmText);
        this.$modal.find('.fs-lms-modal-cancel').text(cancelText);

        openModal(this.$modal);

        return new Promise((resolve, reject) => {
            this._reject = reject;

            $confirmBtn
                .off('click.confirm')
                .on('click.confirm', () => { this._close(); resolve(); });

            this.$modal.find('.fs-lms-modal-cancel, .fs-lms-modal-close, .fs-lms-modal-backdrop')
                .off('click.confirm')
                .on('click.confirm', () => { this._close(); reject('cancel'); });

            bindEsc('confirm', () => { this._close(); reject('esc'); });
        });
    },

    close() {
        this._close();
    },
    /**
     * Закрывает модалку, отвязывает слушатели и сбрасывает состояние.
     * @private
     */
    _close() {
        closeModal(this.$modal);
        unbindEsc('confirm');
    },
};

export { ConfirmModal };