import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * Модальное окно создания/редактирования учебных периодов.
 * Реализовано по образу и подобию TaxonomyModal (слабая связность).
 *
 * @namespace AcademicPeriodModal
 */
export const AcademicPeriodModal = {
    /** @type {JQuery} */
    $modal: null,
    /** @type {Function[]} Стек коллбеков сохранения */
    _saveCallbacks: [],
    _initialized: false,

    // Кэшированные поля формы
    $idInput: null,
    $nameInput: null,
    $startDateInput: null,
    $endDateInput: null,
    $isCurrentInput: null,
    $actionInput: null,

    $saveBtn: null,
    $titleEl: null,
    $idContainer: null,

    /**
     * Инициализация модуля. Кэширует DOM и привязывает базовые события закрытия/сохранения.
     */
    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-academic-period-modal');
        if (!this.$modal.length) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    /** Кэширует элементы для повторного использования. @private */
    _cacheElements() {
        this.$idInput          = $('#period_id');
        this.$nameInput        = $('#period_name');
        this.$startDateInput   = $('#period_start_date');
        this.$endDateInput     = $('#period_end_date');
        this.$isCurrentInput   = $('#period_is_current');
        this.$actionInput      = $('#period_action_type');

        this.$saveBtn          = $('#period-submit-btn');
        this.$titleEl          = $('#period-modal-title');
        this.$idContainer      = $('#period-id-group');

        this.$form             = this.$modal.find('form');
    },

    /** Привязка внутренних событий модалки. @private */
    _bindEvents() {
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        this.$form.on('submit.fs', (e) => {
            e.preventDefault();
            if (!this._validate()) return;
            const formData = this._collectFormData();
            this._saveCallbacks.forEach(cb => cb(formData));
        });

        this.$startDateInput.add(this.$endDateInput).on('change.fs', () => {
            this.$endDateInput[0].setCustomValidity('');
        });

        this.$idInput.on('input.fs', () => {
            this.$idInput[0].setCustomValidity('');
        });
    },

    /**
     * Регистрирует внешний обработчик сохранения.
     * @param {Function} callback
     */
    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

    /**
     * Открывает модалку снаружи в нужном режиме с нужными данными.
     * @param {'add'|'edit'} action
     * @param {Object} [data={}]
     */
    open(action, data = {}) {
        const isUpdate = action === 'edit';

        this.$actionInput.val(action);
        this.$titleEl.text(isUpdate ? 'Редактировать учебный период' : 'Создать учебный период');
        this.$saveBtn.text(isUpdate ? 'Сохранить изменения' : 'Создать период');

        this.$idContainer.toggle(!isUpdate);
        this.$idInput.prop('readonly', isUpdate).prop('required', !isUpdate);

        if (isUpdate) {
            this.$idInput.val(data.id ?? '');
            this.$nameInput.val(data.name ?? '');
            this.$startDateInput.val(data.start_date ?? '');
            this.$endDateInput.val(data.end_date ?? '');
            this.$isCurrentInput.prop('checked', !!data.is_current);
        } else {
            this._resetForm();
        }

        openModal(this.$modal);
        bindEsc('academic_period', () => this.close());

        // Фокус на первое доступное поле
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                if (isUpdate) {
                    this.$nameInput.trigger('focus');
                } else {
                    this.$idInput.trigger('focus');
                }
            });
        });
    },

    /** Закрывает модалку и сбрасывает форму. */
    close() {
        closeModal(this.$modal);
        unbindEsc('academic_period');
        this._resetForm();
    },

    /**
     * Изменяет состояние кнопки (загрузка/обычное)
     * @param {boolean} loading
     */
    setSaveState(loading) {
        const isUpdate = this.$actionInput.val() === 'edit';
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : (isUpdate ? 'Сохранить изменения' : 'Создать период'));
    },

    _validate() {
        if (new Date(this.$startDateInput.val()) > new Date(this.$endDateInput.val())) {
            this.$endDateInput[0].setCustomValidity('Дата окончания не может быть раньше даты начала.');
            this.$endDateInput[0].reportValidity();
            return false;
        }
        return true;
    },

    /**
     * Устанавливает нативную ошибку валидации на поле ID.
     * @param {string} message
     */
    setIdError(message) {
        this.$idInput[0].setCustomValidity(message);
        this.$idInput[0].reportValidity();
    },

    /** Сбрасывает поля и убирает ошибки @private */
    _resetForm() {
        this.$idInput.val('').prop('readonly', false);
        this.$idInput[0].setCustomValidity('');
        this.$nameInput.val('');
        this.$startDateInput.val('');
        this.$endDateInput.val('');
        this.$endDateInput[0].setCustomValidity('');
        this.$isCurrentInput.prop('checked', false);
        this.$actionInput.val('add');
    },

    /** Собирает чистый объект данных. @returns {Object} @private */
    _collectFormData() {
        return {
            action_type: this.$actionInput.val(),
            id:          this.$idInput.val().trim(),
            name:        this.$nameInput.val().trim(),
            start_date:  this.$startDateInput.val(),
            end_date:    this.$endDateInput.val(),
            is_current:  this.$isCurrentInput.is(':checked') ? '1' : '0',
        };
    },
};