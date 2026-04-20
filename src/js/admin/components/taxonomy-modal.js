import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const TaxonomyModal = {
    _onSaveCallback: null,
    _initialized: false,
    $modal: null,

    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-taxonomy-modal');

        if (!this.$modal.length) return;

        this._initialized = true;
        this._bindEvents();
    },

    _bindEvents() {
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .fs-lms-modal-close, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        this.$modal.on('click', '.js-modal-save', (e) => {
            e.preventDefault();
            if (typeof this._onSaveCallback === 'function') {
                this._onSaveCallback(this._collectFormData());
            }
        });
    },

    onSave(callback) {
        this._onSaveCallback = callback;
    },

    open(action, data = {}) {
        const isUpdate = action === 'update';

        $('#tax-action').val(action);
        $('#modal-title').text(isUpdate ? 'Редактировать название' : 'Новая таксономия');
        $('#slug-container').toggle(!isUpdate);

        if (isUpdate) {
            $('#tax-original-slug').val(data.slug ?? '');
        } else {
            $('#tax-slug').val('');
            $('#tax-original-slug').val('');
        }

        $('#tax-name').val(isUpdate ? (data.name ?? '') : '');

        const displayType = (isUpdate && data.display) ? data.display : 'select';
        this.$modal.find(`input[name="tax_display_type"][value="${displayType}"]`).prop('checked', true);

        openModal(this.$modal);
        bindEsc('taxonomy', () => this.close());

        setTimeout(() => $('#tax-name').trigger('focus'), 210);
    },

    close() {
        closeModal(this.$modal, () => {
            $('#tax-name, #tax-slug, #tax-original-slug').val('');
            $('#tax-action').val('store');
            this.$modal.find('input[name="tax_display_type"][value="select"]').prop('checked', true);
        });
        unbindEsc('taxonomy');
    },

    setSaveState(loading) {
        $('.js-modal-save')
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : 'Сохранить');
    },

    _collectFormData() {
        const action = $('#tax-action').val();
        return {
            action,
            subject_key:  $('#tax-subject-key').val(),
            tax_slug:     action === 'update' ? $('#tax-original-slug').val() : $('#tax-slug').val(),
            tax_name:     $('#tax-name').val(),
            display_type: this.$modal.find('input[name="tax_display_type"]:checked').val(),
        };
    },
};
