import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const SubjectModal = {
    $modal: null,

    init() {
        this.$modal = $('#fs-subject-modal');

        if (!this.$modal.length) {
            return;
        }

        $('#open-subject-modal').on('click', () => this.open());

        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-close, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });
    },

    open() {
        openModal(this.$modal);
        bindEsc('subject', () => this.close());
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('subject');
    },
};
