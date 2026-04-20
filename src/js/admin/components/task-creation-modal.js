import '../_types.js';
import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const TaskCreationModal = {
    _initialized: false,
    _callbacks: { onOpen: null, onTermChange: null, onSubmit: null },
    $modal: null,
    $form: null,

    init() {
        this.$modal = $('#fs-task-modal');
        this.$form  = $('#fs-task-creation-form');

        if (!this.$modal.length || this._initialized) {
            return;
        }

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        $('body')
            .off('click.fs', '.page-title-action')
            .on('click.fs', '.page-title-action', (e) => {
                const href = $(e.currentTarget).attr('href') || '';
                if (href.includes('post-new.php') && href.includes('post_type=' + fs_lms_task_data.post_type)) {
                    e.preventDefault();
                    this.open();
                }
            });

        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .fs-lms-modal-close, .js-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        $('#fs-modal-term').off('change.fs').on('change.fs', (e) => {
            const termSlug = $(e.target).find('option:selected').data('slug');
            if (typeof this._callbacks.onTermChange === 'function') {
                this._callbacks.onTermChange(termSlug);
            }
        });

        this.$form.off('submit.fs').on('submit.fs', (e) => {
            e.preventDefault();
            if (typeof this._callbacks.onSubmit === 'function') {
                this._callbacks.onSubmit(this._getFormData());
            }
        });
    },

    onOpen(fn)       { this._callbacks.onOpen = fn; },
    onTermChange(fn) { this._callbacks.onTermChange = fn; },
    onSubmit(fn)     { this._callbacks.onSubmit = fn; },

    open() {
        openModal(this.$modal);
        bindEsc('task_creation', () => this.close());

        if (this.$form && this.$form[0]) {
            this.$form[0].reset();
        }

        if (typeof this._callbacks.onOpen === 'function') {
            this._callbacks.onOpen();
        }
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('task_creation');
    },

    setTerms(html) {
        $('#fs-modal-term').html(html).prop('disabled', false);
    },

    setBoilerplates(html) {
        $('#fs-modal-boilerplate').html(html).prop('disabled', false);
    },

    setSubmitState(loading) {
        $('#fs-modal-submit')
            .prop('disabled', loading)
            .text(loading ? 'Создание...' : 'Продолжить');
    },

    _getFormData() {
        return {
            termId:         $('#fs-modal-term').val(),
            title:          $('#fs-modal-title').val(),
            boilerplateUid: $('#fs-modal-boilerplate').val(),
        };
    },
};
