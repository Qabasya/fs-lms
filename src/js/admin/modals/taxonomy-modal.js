/**
 * @module TaxonomyModal
 * @description UI-компонент модального окна для создания и редактирования таксономий.
 *              Отвечает за:
 *              - Управление жизненным циклом модалки (открытие, закрытие, сброс)
 *              - Переключение между режимами "Создание" (store) и "Редактирование" (update)
 *              - Интеграцию с глобальным менеджером валидации форм
 *              - Сбор данных формы (включая корректную обработку чекбоксов и радио-кнопок)
 *              - Уведомление внешних менеджеров через паттерн Pub/Sub
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 * @requires initFormValidation - утилита инициализации комплексной валидации формы
 */

import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';
import { initFormValidation } from '../../common/validation-manager.js';

const $ = jQuery;

/**
 * UI-компонент модального окна управления таксономиями.
 * Работает в связке с TaxonomyModalManager, который обрабатывает AJAX-запросы.
 */
export const TaxonomyModal = {
    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,

    /** @type {Function[]} Массив колбэков, вызываемых при успешной валидации и отправке формы */
    _saveCallbacks: [],

    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    // Кэшированные jQuery-объекты полей формы.
    // Поиск элементов через селекторы — относительно дорогая операция,
    // поэтому мы сохраняем ссылки на них один раз при инициализации.

    /** @type {jQuery} Поле ввода названия таксономии */
    $nameInput: null,
    /** @type {jQuery} Скрытое поле с оригинальным слагом (нужно только для режима update) */
    $originalSlugInput: null,
    /** @type {jQuery} Скрытое поле типа действия ('store' или 'update') */
    $actionInput: null,
    /** @type {jQuery} Скрытое поле с ключом предмета (контекст таксономии) */
    $subjectKeyInput: null,
    /** @type {jQuery} Набор радио-кнопок для выбора типа отображения (display_type) */
    $displayInputs: null,
    /** @type {jQuery} Чекбокс обязательности заполнения таксономии */
    $isRequiredInput: null,
    /** @type {jQuery} Кнопка сохранения формы */
    $saveBtn: null,
    /** @type {jQuery} Элемент заголовка модального окна */
    $titleEl: null,
    /** @type {jQuery} Форма модального окна */
    $form: null,

    /**
     * Функция комплексной валидации формы.
     * Возвращается утилитой initFormValidation и объединяет все правила валидации.
     * @private
     * @type {Function}
     */
    _validateAll: null,

    /**
     * Инициализация компонента.
     * Выполняет проверку существования модалки, кэширует элементы и навешивает события.
     */
    init() {
        // Защита от повторной инициализации (паттерн Singleton)
        if (this._initialized) return;

        this.$modal = $('#fs-taxonomy-modal');
        if (!this.$modal.length) return; // Если модалки нет в DOM, прекращаем инициализацию

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    /**
     * Кэширование DOM-элементов.
     * @private
     */
    _cacheElements() {
        this.$nameInput         = $('#tax-name');
        this.$originalSlugInput = $('#tax-original-slug');
        this.$actionInput       = $('#tax-action');
        this.$subjectKeyInput   = $('#tax-subject-key');
        this.$displayInputs     = this.$modal.find('input[name="tax_display_type"]');
        this.$saveBtn           = $('.js-modal-save');
        this.$titleEl           = this.$modal.find('#modal-title');
        this.$isRequiredInput   = $('#tax-is-required');
        this.$form              = this.$modal.find('form');

        // Инициализируем внешнюю систему валидации для этой конкретной формы.
        // Она вернет функцию, которую мы будем вызывать при попытке отправки.
        this._validateAll = initFormValidation(this.$form[0]);
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // Закрытие модалки при клике на фон, крестик или кнопку отмены
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .fs-lms-modal-close, .js-modal-close, .fs-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // Обработчик кнопки сохранения.
        // Используем неймспейсинг '.fs' для возможности точечного удаления этого обработчика в будущем.
        this.$saveBtn.on('click.fs', (e) => {
            e.preventDefault();

            // Если валидация не прошла, прерываем выполнение. 
            // Менеджер валидации сам покажет ошибки пользователю.
            if (!this._validate()) return;

            const formData = this._collectFormData();
            // Уведомляем всех подписчиков (например, TaxonomyModalManager) о готовности данных
            this._saveCallbacks.forEach(cb => cb(formData));
        });

        // UX: Улучшение обратной связи при вводе.
        // Как только пользователь начинает вводить текст в поле названия, 
        // мы сразу удаляем класс ошибки (если он был), чтобы интерфейс реагировал мгновенно.
        this.$nameInput.on('input.fs', () => {
            this.$nameInput.removeClass('fs-input-error');
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
     * @param {'store'|'update'} action - Режим работы модалки.
     * @param {Object} [data={}] - Объект с данными для заполнения формы (только для режима 'update').
     * @param {string} [data.slug] - Слаг таксономии.
     * @param {string} [data.name] - Название таксономии.
     * @param {string} [data.display] - Тип отображения ('select' и т.д.).
     * @param {boolean} [data.is_required] - Флаг обязательности.
     */
    open(action, data = {}) {
        const isUpdate = action === 'update';

        // Настраиваем скрытые поля и тексты в зависимости от режима
        this.$actionInput.val(action);
        this.$titleEl.text(isUpdate ? 'Редактировать название' : 'Новая таксономия');

        if (isUpdate) {
            // Заполняем форму переданными данными. Оператор ?? обеспечивает fallback на пустую строку.
            this.$originalSlugInput.val(data.slug ?? '');
            this.$nameInput.val(data.name ?? '');
        } else {
            // В режиме создания очищаем поля, на всякий случай
            this.$originalSlugInput.val('');
            this.$nameInput.val('');
        }

        // Установка значений для радио-кнопок и чекбокса.
        // Для радио-кнопок используем .filter(), чтобы найти нужный input по значению value.
        const displayType = (isUpdate && data.display) ? data.display : 'select';
        this.$displayInputs.filter(`[value="${displayType}"]`).prop('checked', true);

        // Для чекбокса преобразуем значение в строгий boolean через !!
        this.$isRequiredInput.prop('checked', isUpdate ? !!data.is_required : false);

        // Базовая логика открытия модалки и привязки клавиши Esc
        openModal(this.$modal);
        bindEsc('taxonomy', () => this.close());

        // ПАТТЕРН: Двойной requestAnimationFrame для управления фокусом.
        // Браузеру требуется время на отрисовку модалки (CSS transition). 
        // Двойной RAF гарантирует, что фокус будет установлен после завершения перерисовки кадра,
        // предотвращая "дерганье" интерфейса или потерю фокуса.
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
        unbindEsc('taxonomy');
        this._resetForm();
    },

    /**
     * Управление состоянием кнопки сохранения (блокировка и изменение текста).
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setSaveState(loading) {
        this.$saveBtn
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : 'Сохранить');
    },

    /**
     * Валидация формы перед отправкой.
     * Делегирует проверку внешней функции _validateAll.
     * @private
     * @returns {boolean} true, если форма валидна; false, если есть ошибки.
     */
    _validate() {
        return this._validateAll();
    },

    /**
     * Сброс формы к исходному состоянию (режим создания по умолчанию).
     * @private
     */
    _resetForm() {
        this.$nameInput.val('').removeClass('fs-input-error');
        this.$originalSlugInput.val('');
        this.$actionInput.val('store');

        // Возвращаем радио-кнопки и чекбокс к значениям по умолчанию
        this.$displayInputs.filter('[value="select"]').prop('checked', true);
        this.$isRequiredInput.prop('checked', false);
    },

    /**
     * Сбор данных из полей формы в единый объект.
     * @private
     * @returns {Object} Объект с подготовленными данными для отправки на сервер.
     */
    _collectFormData() {
        const action = this.$actionInput.val();
        return {
            action,
            subject_key:  this.$subjectKeyInput.val(),
            tax_slug:     this.$originalSlugInput.val(),
            tax_name:     this.$nameInput.val().trim(), // .trim() удаляет случайные пробелы по краям
            display_type: this.$displayInputs.filter(':checked').val(),

            // ВАЖНО: HTML-формы отправляют чекбоксы как строки.
            // Если чекбокс отмечен, is(':checked') вернет true, и мы отправим '1'.
            // Если не отмечен, вернет false, и мы отправим '0'.
            // Это стандартная практика для корректной обработки булевых значений на бэкенде (PHP).
            is_required:  this.$isRequiredInput.is(':checked') ? '1' : '0',
        };
    },
};