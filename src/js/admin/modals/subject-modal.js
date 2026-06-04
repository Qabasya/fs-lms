import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const SubjectModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,

    $form: null,
    $nameInput: null,
    $keyInput: null,
    $tasksCountInput: null,
    $nonceInput: null,

    $saveBtn: null,

    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-subject-modal');
        if (!this.$modal.length) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    _cacheElements() {
        this.$nameInput      = $('#subj_name');
        this.$keyInput       = $('#subj_key');
        this.$tasksCountInput = $('#subj_tasks_count');
        this.$nonceInput     = this.$modal.find('input[name="security"]');
        this.$saveBtn        = this.$modal.find('button[type="submit"]');
        this.$form           = this.$modal.find('form');
    },

    _bindEvents() {
        $(document).on('click.add-subject', '.js-add-subject', (e) => {
            e.preventDefault();
            this.open();
        });

        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-close, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        this.$form.on('submit.fs', (e) => {
            e.preventDefault();
            if (!this._validate()) return;
            const formData = this._collectFormData();
            this._saveCallbacks.forEach(cb => cb(formData));
        });

        this.$keyInput.on('input.fs', () => {
            this.$keyInput[0].setCustomValidity('');
        });
    },

    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

    open() {
        this._resetForm();
        openModal(this.$modal);
        bindEsc('subject', () => this.close());

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.$nameInput.trigger('focus');
            });
        });
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('subject');
        this._resetForm();
    },

    setSaveState(loading) {
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : 'Создать предмет и CPT');
    },

    setKeyError(message) {
        this.$keyInput[0].setCustomValidity(message);
        this.$keyInput[0].reportValidity();
    },

    _validate() {
        return this.$form[0].checkValidity();
    },

    _resetForm() {
        if (this.$form[0]) this.$form[0].reset();
        this.$keyInput[0].setCustomValidity('');
    },

    _collectFormData() {
        return {
            name:        this.$nameInput.val().trim(),
            key:         this.$keyInput.val().trim(),
            tasks_count: this.$tasksCountInput.val(),
            security:    this.$nonceInput.val(),
        };
    },
};
