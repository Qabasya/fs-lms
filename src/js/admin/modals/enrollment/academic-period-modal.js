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
    $idContainer: null,

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
        this.$idContainer = $('#period-id-group');
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

        // UX: Очистка состояния ошибки валидации, как только пользователь начинает исправлять поле
        this.$startDateInput.add(this.$endDateInput).on('change.fs', () => {
            this.$endDateInput[0].setCustomValidity('');
        });

        this.$idInput.on('input.fs', () => {
            this.$idInput[0].setCustomValidity('');
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

        // В режиме редактирования поле ID скрывается и становится доступным только для чтения,
        // так как изменять первичный ключ существующей записи нельзя.
        // В режиме создания оно обязательно к заполнению (required).
        this.$idContainer.toggle(!isUpdate);
        this.$idInput.prop('readonly', isUpdate).prop('required', !isUpdate);

        if (isUpdate) {
            // Заполняем форму переданными данными. Оператор ?? обеспечивает fallback на пустую строку,
            // если какое-то поле отсутствует в объекте data.
            this.$idInput.val(data.id ?? '');
            this.$nameInput.val(data.name ?? '');
            this.$startDateInput.val(data.start_date ?? '');
            this.$endDateInput.val(data.end_date ?? '');
            this.$isCurrentInput.prop('checked', !!data.is_current); // Приведение к строгому boolean
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
                if (isUpdate) {
                    this.$nameInput.trigger('focus');
                } else {
                    this.$idInput.trigger('focus');
                }
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

        return true;
    },

    /**
     * Программная установка ошибки валидации для поля ID (например, при ответе от сервера о дубликате).
     * @param {string} message - Текст ошибки для отображения.
     */
    setIdError(message) {
        this.$idInput[0].setCustomValidity(message);
        this.$idInput[0].reportValidity();
    },

    /**
     * Сброс формы к исходному состоянию.
     * Очищает значения, снимает флажки и сбрасывает состояния пользовательской валидации.
     * @private
     */
    _resetForm() {
        this.$idInput.val('').prop('readonly', false);
        this.$idInput[0].setCustomValidity(''); // Сброс ошибки валидации
        this.$nameInput.val('');
        this.$startDateInput.val('');
        this.$endDateInput.val('');
        this.$endDateInput[0].setCustomValidity(''); // Сброс ошибки валидации
        this.$isCurrentInput.prop('checked', false);
        this.$actionInput.val('add');
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
        };
    },
};