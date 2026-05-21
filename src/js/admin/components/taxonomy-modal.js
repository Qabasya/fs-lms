import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const TaxonomyModal = {
    $modal: null,
    _saveCallbacks: [],
    _initialized: false,

    $nameInput: null, $slugInput: null, $originalSlugInput: null,
    $actionInput: null, $subjectKeyInput: null,
    $displayInputs: null, $saveBtn: null, $slugContainer: null, $titleEl: null,
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
        this.$slugInput         = $('#tax-slug');
        this.$originalSlugInput = $('#tax-original-slug');
        this.$actionInput       = $('#tax-action');
        this.$subjectKeyInput   = $('#tax-subject-key');
        this.$displayInputs     = this.$modal.find('input[name="tax_display_type"]');
        this.$saveBtn           = $('.js-modal-save');
        this.$slugContainer     = this.$modal.find('#slug-container');
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

        this.$nameInput.add(this.$slugInput).on('input.fs', (e) => {
            $(e.currentTarget).removeClass('fs-input-error');
        });

        this.$slugInput.on('input.fs-validity', () => {
            this.$slugInput[0].setCustomValidity('');
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
        this.$slugContainer.toggle(!isUpdate);

        if (isUpdate) {
            this.$originalSlugInput.val(data.slug ?? '');
            this.$nameInput.val(data.name ?? '');
        } else {
            this.$slugInput.val('');
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
        let isValid = true;
        const required = [this.$nameInput];
        if (this.$actionInput.val() === 'store') required.push(this.$slugInput);

        required.forEach($field => {
            if (!$field.val().trim()) {
                $field.addClass('fs-input-error');
                isValid = false;
            }
        });

        return isValid;
    },

    setSlugError(message) {
        this.$slugInput[0].setCustomValidity(message);
        this.$slugInput[0].reportValidity();
    },

    _resetForm() {
        this.$nameInput.val('').removeClass('fs-input-error');
        this.$slugInput.val('').removeClass('fs-input-error');
        this.$slugInput[0].setCustomValidity('');
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
            tax_slug:     action === 'update' ? this.$originalSlugInput.val() : this.$slugInput.val(),
            tax_name:     this.$nameInput.val().trim(),
            display_type: this.$displayInputs.filter(':checked').val(),
            is_required:  this.$isRequiredInput.is(':checked') ? '1' : '0',
        };
    },
};
