/**
 * @fileoverview Модальное окно для управления таксономиями (создание и редактирование).
 * @description Обеспечивает функционал открытия/закрытия модального окна для создания новой
 *              или редактирования существующей таксономии с возможностью сохранения данных.
 * @requires jQuery - глобальная зависимость WordPress.
 */

const $ = jQuery;

/**
 * Объект для управления модальным окном таксономий.
 * @namespace TaxonomyModal
 * @typedef {Object} TaxonomyModal
 * @property {Function|null} _onSaveCallback - Колбэк-функция, вызываемая при сохранении.
 * @property {boolean} _initialized - Флаг инициализации модуля.
 * @property {jQuery|null} $modal - jQuery-элемент модального окна.
 */
export const TaxonomyModal = {
    /**
     * Колбэк-функция, вызываемая при нажатии кнопки сохранения.
     * @memberof TaxonomyModal
     * @instance
     * @private
     * @type {Function|null}
     */
    _onSaveCallback: null,

    /**
     * Инициализирует модальное окно: проверяет наличие DOM-элемента,
     * предотвращает повторную инициализацию и навешивает обработчики событий.
     * @memberof TaxonomyModal
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     TaxonomyModal.init();
     * });
     */
    init() {
        // Предотвращаем повторную инициализацию
        if (this._initialized) return;

        // Кэшируем jQuery-объект модального окна
        this.$modal = $('#fs-taxonomy-modal');

        // Если модальное окно отсутствует в DOM — прекращаем инициализацию
        if (!this.$modal.length) return;

        // Устанавливаем флаг инициализации
        this._initialized = true;

        // Навешиваем обработчики событий
        this._bindEvents();
    },

    /**
     * Приватный метод: навешивает все обработчики событий модального окна.
     * @memberof TaxonomyModal
     * @instance
     * @private
     * @listens click.js-modal-close - Закрытие окна по кнопке закрытия
     * @listens click#fs-taxonomy-modal - Закрытие окна по клику на фон (overlay)
     * @listens click.js-modal-save - Сохранение данных и вызов колбэка
     * @returns {void}
     */
    _bindEvents() {
        // Обработчик клика по кнопке закрытия
        this.$modal.on('click', '.js-modal-close', (e) => {
            e.preventDefault(); // Предотвращаем стандартное поведение
            this.close();
        });

        // Обработчик клика по фону модального окна (overlay)
        this.$modal.on('click', (e) => {
            // Проверяем, что клик был именно по фону, а не по содержимому
            if ($(e.target).is(this.$modal)) {
                this.close();
            }
        });

        // Обработчик клика по кнопке сохранения
        this.$modal.on('click', '.js-modal-save', (e) => {
            e.preventDefault(); // Предотвращаем стандартное поведение

            // Если установлен колбэк на сохранение, вызываем его с данными формы
            if (typeof this._onSaveCallback === 'function') {
                this._onSaveCallback(this._collectFormData());
            }
        });
    },

    /**
     * Устанавливает колбэк-функцию, которая будет вызвана при сохранении данных.
     * @memberof TaxonomyModal
     * @instance
     * @param {Function} callback - Функция, принимающая объект с данными формы.
     * @returns {void}
     * @example
     * TaxonomyModal.onSave((formData) => {
     *     console.log('Сохранение таксономии', formData);
     *     wp.ajax.post('save_taxonomy', formData);
     * });
     */
    onSave(callback) {
        this._onSaveCallback = callback;
    },

    /**
     * Открывает модальное окно с настройками для создания или редактирования таксономии.
     * @memberof TaxonomyModal
     * @instance
     * @param {'store'|'update'} action - Тип действия: 'store' для создания новой, 'update' для редактирования существующей.
     * @param {{ slug?: string, name?: string, display?: string }} [data] - Данные для заполнения формы при редактировании.
     * @param {string} [data.slug] - Слаг таксономии (требуется при action='update').
     * @param {string} [data.name] - Название таксономии.
     * @param {string} [data.display] - Тип отображения ('select' или другой).
     * @returns {void}
     * @fires fadeIn - jQuery-анимация появления
     * @example
     * // Открытие для создания новой таксономии
     * TaxonomyModal.open('store');
     *
     * // Открытие для редактирования существующей
     * TaxonomyModal.open('update', {
     *     slug: 'my-taxonomy',
     *     name: 'Моя таксономия',
     *     display: 'select'
     * });
     */
    open(action, data = {}) {
        // Определяем, является ли действие редактированием
        const isUpdate = action === 'update';

        // Устанавливаем значение скрытого поля с типом действия
        $('#tax-action').val(action);

        // Устанавливаем заголовок модального окна в зависимости от действия
        $('#modal-title').text(isUpdate ? 'Редактировать название' : 'Новая таксономия');

        // Показываем или скрываем поле ввода слага в зависимости от действия
        // При редактировании слаг менять нельзя, при создании - можно
        $('#slug-container').toggle(!isUpdate);

        // Заполняем поля данными в зависимости от действия
        if (isUpdate) {
            // При редактировании: сохраняем оригинальный слаг в скрытое поле
            $('#tax-original-slug').val(data.slug ?? '');
        } else {
            // При создании: очищаем поля
            $('#tax-slug').val('');
            $('#tax-original-slug').val('');
        }

        // Устанавливаем название таксономии (при редактировании - существующее, при создании - пустое)
        $('#tax-name').val(isUpdate ? (data.name ?? '') : '');

        // Устанавливаем тип отображения (при редактировании берем из данных, иначе 'select' по умолчанию)
        const displayType = (isUpdate && data.display) ? data.display : 'select';
        this.$modal.find(`input[name="tax_display_type"][value="${displayType}"]`).prop('checked', true);

        // Плавно показываем модальное окно и устанавливаем фокус на поле ввода названия
        this.$modal.fadeIn(200, () => $('#tax-name').trigger('focus'));
    },

    /**
     * Закрывает модальное окно с плавным исчезновением и сбрасывает все поля формы.
     * @memberof TaxonomyModal
     * @instance
     * @returns {void}
     * @fires fadeOut - jQuery-анимация исчезновения
     * @example
     * // Программное закрытие окна
     * TaxonomyModal.close();
     */
    close() {
        // Плавно скрываем модальное окно
        this.$modal.fadeOut(200, () => {
            // После завершения анимации сбрасываем все поля формы
            $('#tax-name, #tax-slug, #tax-original-slug').val('');
            // Сбрасываем действие на 'store' (создание)
            $('#tax-action').val('store');
            // Сбрасываем тип отображения на 'select' по умолчанию
            this.$modal.find('input[name="tax_display_type"][value="select"]').prop('checked', true);
        });
    },

    /**
     * Устанавливает состояние кнопки сохранения (заблокирована/активна) и изменяет её текст.
     * Используется для предотвращения повторного сохранения во время асинхронных запросов.
     * @memberof TaxonomyModal
     * @instance
     * @param {boolean} loading - Если true, кнопка блокируется и текст меняется на "Сохранение...";
     *                            если false, кнопка разблокируется и текст становится "Сохранить".
     * @returns {void}
     * @example
     * // Блокировка кнопки во время AJAX-запроса
     * TaxonomyModal.setSaveState(true);
     * wp.ajax.post(...).always(() => TaxonomyModal.setSaveState(false));
     */
    setSaveState(loading) {
        $('.js-modal-save')
            .prop('disabled', loading)
            .text(loading ? 'Сохранение...' : 'Сохранить');
    },

    /**
     * Приватный метод: собирает и возвращает данные из формы.
     * @memberof TaxonomyModal
     * @instance
     * @private
     * @returns {TaxonomyFormData} Объект с данными формы таксономии.
     * @property {string} action - Тип действия ('store' или 'update').
     * @property {string} subject_key - Ключ предмета (из скрытого поля).
     * @property {string} tax_slug - Слаг таксономии (при update берется из оригинального слага, при store из поля ввода).
     * @property {string} tax_name - Название таксономии.
     * @property {string} display_type - Тип отображения (значение выбранной радиокнопки).
     */
    _collectFormData() {
        const action = $('#tax-action').val();

        return {
            action,
            subject_key: $('#tax-subject-key').val(),
            // При обновлении используем оригинальный слаг, при создании - новый
            tax_slug: action === 'update' ? $('#tax-original-slug').val() : $('#tax-slug').val(),
            tax_name: $('#tax-name').val(),
            display_type: this.$modal.find('input[name="tax_display_type"]:checked').val(),
        };
    },
};

/**
 * @typedef {Object} TaxonomyFormData
 * @property {string} action - Тип действия: 'store' (создание) или 'update' (обновление).
 * @property {string} subject_key - Ключ предмета, к которому привязывается таксономия.
 * @property {string} tax_slug - Уникальный слаг таксономии.
 * @property {string} tax_name - Отображаемое название таксономии.
 * @property {string} display_type - Тип отображения таксономии в интерфейсе.
 */

/**
 * @typedef {Object} TaxonomyModalData
 * @property {string} [slug] - Слаг таксономии (для редактирования).
 * @property {string} [name] - Название таксономии (для редактирования).
 * @property {string} [display] - Тип отображения (для редактирования).
 */