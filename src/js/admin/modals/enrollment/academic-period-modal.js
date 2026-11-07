/**
 * @module AcademicPeriodModal
 * @description UI-компонент модального окна для создания и редактирования учебных периодов.
 *              Отвечает за:
 *              - Кэширование DOM-элементов для оптимизации производительности
 *              - Управление состоянием модального окна (открытие, закрытие, сброс)
 *              - Переключение между режимами "Создание" и "Редактирование"
 *              - Клиентскую валидацию дат с использованием HTML5 Constraint Validation API
 *              - Сбор данных формы и уведомление внешних менеджеров через паттерн Pub/Sub
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../../modules/modal-base.js';

const $ = jQuery;

export const AcademicPeriodModal = {
    /** @type {jQuery} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {Function[]} Массив колбэков, вызываемых при успешной валидации и отправке формы */
    _saveCallbacks: [],

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    // Кэшированные jQuery-объекты полей формы для избежания повторных поисков в DOM
    $idInput: null,
    $nameInput: null,
    $startDateInput: null,
    $endDateInput: null,
    $isCurrentInput: null,
    $actionInput: null,
    $form: null,

    // Кэшированные элементы управления и отображения
    $saveBtn: null,
    $titleEl: null,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки, кэширует элементы и навешивает события.
     */
    init() {
        if (this._initialized) return;

        this.$modal = $('#fs-academic-period-modal');
        if (!this.$modal.length) return; // Защита: если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    /**
     * Кэширование DOM-элементов.
     * Поиск элементов через jQuery — относительно дорогая операция.
     * Сохраняя ссылки один раз при инициализации, мы ускоряем последующую работу с формой.
     * @private
     */
    _cacheElements() {
        this.$idInput        = $('#period_id');
        this.$nameInput      = $('#period_name');
        this.$startDateInput = $('#period_start_date');
        this.$endDateInput   = $('#period_end_date');
        this.$isCurrentInput = $('#period_is_current');
        this.$actionInput    = $('#period_action_type');

        this.$saveBtn     = $('#period-submit-btn');
        this.$titleEl     = $('#period-modal-title');
        this.$form        = this.$modal.find('form');
    },

    /**
     * Привязка обработчиков событий.
     * Используется неймспейсинг событий (`.fs`), чтобы при необходимости можно было
     * безопасно удалить только эти обработчики через .off('.fs'), не затрагивая другие.
     * @private
     */
    _bindEvents() {
        // Закрытие модалки при клике на фон, кнопку отмены или крестик
        this.$modal.on('click.fs', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // Перехват отправки формы
        this.$form.on('submit.fs', (e) => {
            e.preventDefault(); // Предотвращаем стандартную перезагрузку страницы браузером

            if (!this._validate()) return; // Если валидация не прошла, прерываем выполнение

            const formData = this._collectFormData();
            // Уведомляем всех подписчиков (например, AcademicPeriodModalManager) о готовности данных
            this._saveCallbacks.forEach(cb => cb(formData));
        });

        this.$modal.on('click.fs', '.js-period-add-holiday', () => this._addHoliday());
        this.$modal.on('click.fs', '.js-period-remove-holiday', (e) => $(e.currentTarget).closest('.js-period-holiday').remove());
        this.$modal.on('change.fs', '.js-period-holiday input', (e) => e.currentTarget.setCustomValidity(''));

        // UX: Очистка состояния ошибки валидации, как только пользователь начинает исправлять поле
        this.$startDateInput.add(this.$endDateInput).on('change.fs', () => {
            this.$endDateInput[0].setCustomValidity('');
        });
    },

    /**
     * Регистрация колбэка, который будет вызван при попытке сохранения формы.
     * Реализует паттерн Pub/Sub (Издатель-Подписчик).
     * @param {Function} callback - Функция, принимающая объект с данными формы.
     */
    onSave(callback) {
        if (typeof callback === 'function') {
            this._saveCallbacks.push(callback);
        }
    },

    /**
     * Открытие модального окна в режиме создания или редактирования.
     * @param {'add'|'edit'} action - Режим работы модалки.
     * @param {Object} [data={}] - Объект с данными для заполнения формы (только для режима 'edit').
     */
    open(action, data = {}) {
        const isUpdate = action === 'edit';

        // Настраиваем тексты и скрытые поля в зависимости от режима
        this.$actionInput.val(action);
        this.$titleEl.text(isUpdate ? 'Редактировать учебный период' : 'Создать учебный период');
        this.$saveBtn.text(isUpdate ? 'Сохранить изменения' : 'Создать период');

        if (isUpdate) {
            // Технический ключ существующего периода — в скрытом поле (на сервере он не меняется).
            // Оператор ?? обеспечивает fallback на пустую строку, если поле отсутствует в data.
            this.$idInput.val(data.id ?? '');
            this.$nameInput.val(data.name ?? '');
            this.$startDateInput.val(data.start_date ?? '');
            this.$endDateInput.val(data.end_date ?? '');
            this.$isCurrentInput.prop('checked', !!data.is_current); // Приведение к строгому boolean
            this.$modal.find('.js-period-holidays').empty();
            const dates = [...new Set(Array.isArray(data.holidays) ? data.holidays : [])].sort();
            let from = '', until = '';
            dates.forEach(date => {
                const next = until ? new Date(`${until}T00:00:00Z`) : null;
                if (next) next.setUTCDate(next.getUTCDate() + 1);
                if (next && next.toISOString().slice(0, 10) !== date) {
                    this._addHoliday(from, until);
                    from = '';
                }
                if (!from) from = date;
                until = date;
            });
            if (from) this._addHoliday(from, until);
        } else {
            this._resetForm();
        }

        // Базовая логика открытия модалки и привязки клавиши Esc
        openModal(this.$modal);
        bindEsc('academic_period', () => this.close());

        // ПАТТЕРН: Двойной requestAnimationFrame для управления фокусом.
        // Браузеру требуется время на отрисовку модалки (CSS transition). 
        // Если вызвать .focus() сразу, фокус может потеряться или вызвать "дерганье" интерфейса.
        // Двойной RAF гарантирует, что фокус будет установлен после завершения перерисовки кадра.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.$nameInput.trigger('focus');
            });
        });
    },

    /**
     * Закрытие модального окна и очистка состояния.
     */
    close() {
        closeModal(this.$modal);
        unbindEsc('academic_period');
        this._resetForm();
    },

    /**
     * Управление состоянием кнопки сохранения (блокировка и изменение текста).
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setSaveState(loading) {
        const isUpdate = this.$actionInput.val() === 'edit';
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : (isUpdate ? 'Сохранить изменения' : 'Создать период'));
    },

    /**
     * Клиентская валидация формы.
     * Использует HTML5 Constraint Validation API для отображения ошибок.
     * @private
     * @returns {boolean} true, если форма валидна; false, если есть ошибки.
     */
    _validate() {
        const startDate = new Date(this.$startDateInput.val());
        const endDate = new Date(this.$endDateInput.val());

        if (startDate > endDate) {
            // setCustomValidity устанавливает сообщение об ошибке для встроенного в браузер механизма валидации.
            // Пустая строка '' означает, что поле валидно.
            this.$endDateInput[0].setCustomValidity('Дата окончания не может быть раньше даты начала.');

            // reportValidity() принудительно показывает браузерное всплывающее сообщение об ошибке 
            // и подсвечивает поле красным.
            this.$endDateInput[0].reportValidity();
            return false;
        }

        let valid = true;
        this.$modal.find('.js-period-holiday').each((_, row) => {
            const from = row.querySelector('[data-from]');
            const until = row.querySelector('[data-until]');
            until.setCustomValidity('');
            if (from.value < this.$startDateInput.val() || until.value > this.$endDateInput.val() || from.value > until.value) {
                until.setCustomValidity('Выходные должны быть внутри периода; дата окончания — не раньше начала.');
                if (valid) until.reportValidity();
                valid = false;
            }
        });
        if (!valid) return false;

        return true;
    },

    /**
     * Сброс формы к исходному состоянию.
     * Очищает значения, снимает флажки и сбрасывает состояния пользовательской валидации.
     * @private
     */
    _resetForm() {
        this.$idInput.val('');
        this.$nameInput.val('');
        this.$startDateInput.val('');
        this.$endDateInput.val('');
        this.$endDateInput[0].setCustomValidity(''); // Сброс ошибки валидации
        this.$isCurrentInput.prop('checked', false);
        this.$actionInput.val('add');
        this.$modal.find('.js-period-holidays').empty();
    },

    _addHoliday(from = '', until = '') {
        const $row = $('<div class="fs-form-row js-period-holiday"><div class="fs-form-group"><label>С <input type="date" data-from required></label></div><div class="fs-form-group"><label>По <input type="date" data-until required></label></div><button type="button" class="button js-period-remove-holiday">Удалить</button></div>');
        $row.find('[data-from]').val(from);
        $row.find('[data-until]').val(until);
        this.$modal.find('.js-period-holidays').append($row);
    },

    /**
     * Сбор данных из полей формы в единый объект.
     * @private
     * @returns {Object} Объект с подготовленными данными для отправки на сервер.
     */
    _collectFormData() {
        return {
            action_type: this.$actionInput.val(),
            // .trim() удаляет случайные пробелы в начале и конце строки, предотвращая ошибки "пустых" значений
            id:          this.$idInput.val().trim(),
            name:        this.$nameInput.val().trim(),
            start_date:  this.$startDateInput.val(),
            end_date:    this.$endDateInput.val(),
            // PHP интерпретирует данные форм как строки. Передача '1' или '0' для чекбокса 
            // является стандартом для корректной обработки булевых значений на бэкенде WordPress.
            is_current:  this.$isCurrentInput.is(':checked') ? '1' : '0',
            holiday_ranges: this.$modal.find('.js-period-holiday').map((_, row) => ({
                from: row.querySelector('[data-from]').value,
                until: row.querySelector('[data-until]').value,
            })).get(),
        };
    },
};
