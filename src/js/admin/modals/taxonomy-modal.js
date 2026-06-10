import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const TaxonomyModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,

    $nameInput: null, $originalSlugInput: null,
    $actionInput: null, $subjectKeyInput: null,
    $displayInputs: null, $saveBtn: null, $titleEl: null,
    $isRequiredInput: null,

    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-taxonomy-modal');
        if (!this.$modal.length) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    _cacheElements() {
        this.$nameInput         = $('#tax-name');
        this.$originalSlugInput = $('#tax-original-slug');
        this.$actionInput       = $('#tax-action');
        this.$subjectKeyInput   = $('#tax-subject-key');
        this.$displayInputs     = this.$modal.find('input[name="tax_display_type"]');
        this.$saveBtn           = $('.js-modal-save');
        this.$titleEl           = this.$modal.find('#modal-title');
        this.$isRequiredInput   = $('#tax-is-required');
    },

    _bindEvents() {
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .fs-lms-modal-close, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        this.$saveBtn.on('click.fs', (e) => {
            e.preventDefault();
            if (!this._validate()) return;

            const formData = this._collectFormData();
            this._saveCallbacks.forEach(cb => cb(formData));
        });

        this.$nameInput.on('input.fs', () => {
            this.$nameInput.removeClass('fs-input-error');
        });
    },

    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

    open(action, data = {}) {
        const isUpdate = action === 'update';

        this.$actionInput.val(action);
        this.$titleEl.text(isUpdate ? 'Редактировать название' : 'Новая таксономия');

        if (isUpdate) {
            this.$originalSlugInput.val(data.slug ?? '');
            this.$nameInput.val(data.name ?? '');
        } else {
            this.$originalSlugInput.val('');
            this.$nameInput.val('');
        }

        const displayType = (isUpdate && data.display) ? data.display : 'select';
        this.$displayInputs.filter(`[value="${displayType}"]`).prop('checked', true);
        this.$isRequiredInput.prop('checked', isUpdate ? !!data.is_required : false);

        openModal(this.$modal);
        bindEsc('taxonomy', () => this.close());

        requestAnimationFrame(() => requestAnimationFrame(() => this.$nameInput.trigger('focus')));
    },

    close() {
        closeModal(this.$modal);
        unbindEsc('taxonomy');
        this._resetForm();
    },

    setSaveState(loading) {
        this.$saveBtn.prop('disabled', loading).text(loading ? 'Сохранение...' : 'Сохранить');
    },

    _validate() {
        const valid = !! this.$nameInput.val().trim();
        if ( ! valid ) { this.$nameInput.addClass('fs-input-error'); }
        return valid;
    },

    _resetForm() {
        this.$nameInput.val('').removeClass('fs-input-error');
        this.$originalSlugInput.val('');
        this.$actionInput.val('store');
        this.$displayInputs.filter('[value="select"]').prop('checked', true);
        this.$isRequiredInput.prop('checked', false);
    },

    _collectFormData() {
        const action = this.$actionInput.val();
        return {
            action,
            subject_key:  this.$subjectKeyInput.val(),
            tax_slug:     this.$originalSlugInput.val(),
            tax_name:     this.$nameInput.val().trim(),
            display_type: this.$displayInputs.filter(':checked').val(),
            is_required:  this.$isRequiredInput.is(':checked') ? '1' : '0',
        };
    },
};
