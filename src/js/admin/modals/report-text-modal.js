/**
 * @module ReportTextModal
 * @description Модалка журнала «Найденные ошибки»: полный текст сообщения читателя.
 *              Текст лежит в data-text кнопки строки — сеть не нужна.
 *
 * @requires jQuery
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ReportTextModal = {
    $modal: null,
    _initialized: false,

    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-lms-report-text-modal');
        if (!this.$modal.length) return;

        this._initialized = true;

        $(document).on('click', '.js-report-text-open', (e) => {
            e.preventDefault();
            this.open(String($(e.currentTarget).attr('data-text') ?? ''));
        });

        this.$modal.find('.fs-lms-modal-close, .fs-lms-modal-backdrop').on('click', () => this.close());
    },

    open(text) {
        this.$modal.find('.js-report-text').text(text);
        openModal(this.$modal);
        bindEsc('report_text_modal', () => this.close());
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('report_text_modal');
    },
};
