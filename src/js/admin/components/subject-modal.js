import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Модальное окно работы с предметом/темой.
 * @namespace SubjectModal
 */
export const SubjectModal = {
    /** @type {JQuery} */
    $modal: null,

    /**
     * Инициализация: кэширует модалку и привязывает обработчики.
     * ⚠️ В WordPress вызывать строго один раз после загрузки DOM.
     */
    init() {
        this.$modal = $('#fs-subject-modal');

        if (!this.$modal.length) {
            return;
        }

        // Namespace `.subject` защищает от дублирования хендлеров при повторных вызовах init()
        $(document)
            .off('click.subject', '.js-add-subject')
            .on('click.subject', '.js-add-subject', (e) => {
                e.preventDefault();
                this.open();
            });

        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-close, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });
    },

    /**
     * Открывает модалку и включает обработку ESC.
     */
    open() {
        if (!this.$modal?.length) return;
        openModal(this.$modal);
        bindEsc('subject', () => this.close());
    },

    /**
     * Закрывает модалку и отключает обработку ESC.
     */
    close() {
        if (!this.$modal?.length) return;
        closeModal(this.$modal);
        unbindEsc('subject');
    },
};