import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Модальное окно создания/редактирования таксономий.
 * @namespace TaxonomyModal
 */
export const TaxonomyModal = {
    /** @type {JQuery} */
    $modal: null,
    /** @type {Function[]} Стек коллбеков сохранения */
    _saveCallbacks: [],
    _initialized: false,

    // Кэшированные поля
    $nameInput: null, $slugInput: null, $originalSlugInput: null,
    $actionInput: null, $subjectKeyInput: null,
    $displayInputs: null, $saveBtn: null, $slugContainer: null, $titleEl: null,
    $isRequiredInput: null,

    /**
     * Инициализация модуля. Кэширует DOM и привязывает события.
     */
    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-taxonomy-modal');
        if (!this.$modal.length) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    /** Кэширует элементы для повторного использования. @private */
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

    /** Привязка кликов по кнопкам закрытия и сохранения. @private */
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

        // Снимаем подсветку ошибок при вводе
        this.$nameInput.add(this.$slugInput).on('input.fs', (e) => {
            $(e.currentTarget).removeClass('fs-input-error');
        });
    },

    /**
     * Регистрирует обработчик сохранения.
     * @param {Function} callback
     */
    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

    /**
     * Открывает модалку в режиме создания или редактирования.
     * @param {'store'|'update'} action
     * @param {Object} [data={}]
     */
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

    /** Закрывает модалку и сбрасывает форму. */
    close() {
        closeModal(this.$modal);
        unbindEsc('taxonomy');
        this._resetForm();
    },

    /**
     * Переключает состояние кнопки сохранения.
     * @param {boolean} loading
     */
    setSaveState(loading) {
        this.$saveBtn.prop('disabled', loading).text(loading ? 'Сохранение...' : 'Сохранить');
    },

    /** Простая валидация обязательных полей. @returns {boolean} @private */
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

    /** Сбрасывает поля и стили ошибок. @private */
    _resetForm() {
        this.$nameInput.val('').removeClass('fs-input-error');
        this.$slugInput.val('').removeClass('fs-input-error');
        this.$originalSlugInput.val('');
        this.$actionInput.val('store');
        this.$displayInputs.filter('[value="select"]').prop('checked', true);
        this.$isRequiredInput.prop('checked', false);
    },

    /** Собирает данные формы. @returns {Object} @private */
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