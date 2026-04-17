/**
 * @fileoverview Модальное окно для создания задач (Task) в WordPress плагине.
 * @description Обеспечивает функционал создания новых задач через модальное окно с выбором термина,
 *              заголовка и шаблона (boilerplate). Поддерживает колбэки для кастомизации поведения.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных (предполагается, что там объявлены внешние зависимости).
 */

import '../_types.js';

const $ = jQuery;

/**
 * Объект для управления модальным окном создания задач.
 * @namespace TaskCreationModal
 * @typedef {Object} TaskCreationModal
 * @property {boolean} _initialized - Флаг инициализации модуля (предотвращает повторную инициализацию).
 * @property {Object} _callbacks - Хранилище колбэк-функций для событий.
 * @property {Function|null} _callbacks.onOpen - Вызывается при открытии модального окна.
 * @property {Function|null} _callbacks.onTermChange - Вызывается при изменении выбранного термина.
 * @property {Function|null} _callbacks.onSubmit - Вызывается при отправке формы с данными.
 * @property {jQuery|null} $modal - jQuery-объект модального окна.
 * @property {jQuery|null} $form - jQuery-объект формы создания задачи.
 */
export const TaskCreationModal = {
    // Приватные свойства
    _initialized: false,
    _callbacks: { onOpen: null, onTermChange: null, onSubmit: null },

    /**
     * Инициализирует модальное окно: кэширует DOM-элементы, проверяет дублирование инициализации,
     * навешивает обработчики событий.
     * @memberof TaskCreationModal
     * @instance
     * @fires TaskCreationModal#_bindEvents
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM в WordPress
     * jQuery(document).ready(() => {
     *     TaskCreationModal.init();
     * });
     */
    init() {
        // Кэшируем jQuery-объекты для повторного использования
        this.$modal = $('#fs-task-modal');
        this.$form = $('#fs-task-creation-form');

        // Проверяем наличие модального окна в DOM и предотвращаем повторную инициализацию
        if (!this.$modal.length || this._initialized) {
            // Логируем предупреждение в режиме разработки (опционально)
            // if (window.fsLmsDebug) console.warn('[TaskCreationModal] Инициализация пропущена: модальное окно отсутствует или уже инициализировано.');
            return;
        }

        // Устанавливаем флаг инициализации
        this._initialized = true;

        // Навешиваем все обработчики событий
        this._bindEvents();
    },

    /**
     * Приватный метод: навешивает все обработчики событий с пространством имён `.fs`.
     * Использует делегирование и отключение предыдущих обработчиков для избежания дублирования.
     * @memberof TaskCreationModal
     * @instance
     * @private
     * @listens click.page-title-action - Перехват клика по кнопке "Добавить новую" в админке WordPress.
     * @listens click.fs - Закрытие модального окна по различным кнопкам закрытия.
     * @listens change.fs - Изменение выбранного термина в выпадающем списке.
     * @listens submit.fs - Отправка формы создания задачи.
     * @returns {void}
     */
    _bindEvents() {
        // 1. Перехват клика по кнопке "Добавить новую" в WordPress Admin
        // Используем делегирование на body, так как кнопка может быть добавлена динамически
        $('body')
            .off('click.fs', '.page-title-action') // Отключаем предыдущие обработчики
            .on('click.fs', '.page-title-action', (e) => {
                const $target = $(e.currentTarget);
                const href = $target.attr('href') || '';

                // Проверяем, что ссылка ведёт на создание нового поста нашего типа записи
                const postTypeParam = 'post_type=' + fs_lms_task_data.post_type;
                if (href.includes('post-new.php') && href.includes(postTypeParam)) {
                    e.preventDefault(); // Отменяем стандартный переход
                    this.open();       // Открываем модальное окно вместо стандартной формы
                }
            });

        // 2. Закрытие модального окна по кнопкам .fs-close, .fs-modal-cancel, .fs-modal-close
        this.$modal
            .off('click.fs')
            .on('click.fs', '.fs-close, .fs-modal-cancel, .fs-modal-close', (e) => {
                e.preventDefault();
                this.close();
            });

        // 3. Обработка изменения выбранного термина (категории/раздела задачи)
        $('#fs-modal-term')
            .off('change.fs')
            .on('change.fs', (e) => {
                // Извлекаем data-slug из выбранной опции (передаётся с сервера)
                const termSlug = $(e.target).find('option:selected').data('slug');

                // Вызываем пользовательский колбэк, если он установлен
                if (typeof this._callbacks.onTermChange === 'function') {
                    this._callbacks.onTermChange(termSlug);
                }
            });

        // 4. Обработка отправки формы создания задачи
        this.$form
            .off('submit.fs')
            .on('submit.fs', (e) => {
                e.preventDefault(); // Отменяем стандартную отправку формы

                // Вызываем пользовательский колбэк с данными формы
                if (typeof this._callbacks.onSubmit === 'function') {
                    this._callbacks.onSubmit(this._getFormData());
                }
            });
    },

    /**
     * Устанавливает колбэк, который вызывается при открытии модального окна.
     * @memberof TaskCreationModal
     * @instance
     * @param {Function} fn - Функция, вызываемая при открытии.
     * @returns {void}
     * @example
     * TaskCreationModal.onOpen(() => {
     *     console.log('Модальное окно открыто');
     * });
     */
    onOpen(fn) {
        this._callbacks.onOpen = fn;
    },

    /**
     * Устанавливает колбэк, который вызывается при изменении выбранного термина.
     * @memberof TaskCreationModal
     * @instance
     * @param {Function} fn - Функция, принимающая slug выбранного термина.
     * @returns {void}
     * @example
     * TaskCreationModal.onTermChange((termSlug) => {
     *     fetchBoilerplatesByTerm(termSlug);
     * });
     */
    onTermChange(fn) {
        this._callbacks.onTermChange = fn;
    },

    /**
     * Устанавливает колбэк, который вызывается при отправке формы создания задачи.
     * @memberof TaskCreationModal
     * @instance
     * @param {Function} fn - Функция, принимающая объект с данными формы.
     * @returns {void}
     * @example
     * TaskCreationModal.onSubmit((formData) => {
     *     wp.ajax.post('create_task', formData).done((response) => {
     *         console.log('Задача создана', response);
     *     });
     * });
     */
    onSubmit(fn) {
        this._callbacks.onSubmit = fn;
    },

    /**
     * Открывает модальное окно, сбрасывает форму и вызывает колбэк onOpen.
     * @memberof TaskCreationModal
     * @instance
     * @fires TaskCreationModal#onOpen
     * @returns {void}
     * @throws {Error} Если модальное окно не инициализировано.
     * @example
     * // Программное открытие окна
     * TaskCreationModal.open();
     */
    open() {
        // Проверяем, что модальное окно существует
        if (!this.$modal || !this.$modal.length) {
            console.error('[TaskCreationModal] Ошибка: модальное окно не инициализировано.');
            return;
        }

        // Показываем модальное окно (используем .show() вместо .fadeIn() для мгновенного отображения)
        this.$modal.show();

        // Сбрасываем форму: очищаем все поля ввода
        if (this.$form && this.$form[0]) {
            this.$form[0].reset();
        }

        // Вызываем пользовательский колбэк, если он установлен
        if (typeof this._callbacks.onOpen === 'function') {
            this._callbacks.onOpen();
        }
    },

    /**
     * Закрывает модальное окно (скрывает его).
     * @memberof TaskCreationModal
     * @instance
     * @returns {void}
     * @example
     * // Программное закрытие окна
     * TaskCreationModal.close();
     */
    close() {
        if (!this.$modal || !this.$modal.length) {
            console.error('[TaskCreationModal] Ошибка: модальное окно не инициализировано.');
            return;
        }

        this.$modal.hide();
    },

    /**
     * Устанавливает HTML-содержимое для выпадающего списка терминов.
     * @memberof TaskCreationModal
     * @instance
     * @param {string} html - HTML-разметка опций для select-элемента.
     * @returns {void}
     * @example
     * TaskCreationModal.setTerms('<option value="1">Категория 1</option><option value="2">Категория 2</option>');
     */
    setTerms(html) {
        const $termSelect = $('#fs-modal-term');
        $termSelect.html(html).prop('disabled', false);
    },

    /**
     * Устанавливает HTML-содержимое для выпадающего списка шаблонов (boilerplate).
     * @memberof TaskCreationModal
     * @instance
     * @param {string} html - HTML-разметка опций для select-элемента.
     * @returns {void}
     * @example
     * TaskCreationModal.setBoilerplates('<option value="uid1">Шаблон A</option><option value="uid2">Шаблон B</option>');
     */
    setBoilerplates(html) {
        const $boilerplateSelect = $('#fs-modal-boilerplate');
        $boilerplateSelect.html(html).prop('disabled', false);
    },

    /**
     * Устанавливает состояние кнопки отправки формы (заблокирована/активна) и изменяет её текст.
     * Используется для предотвращения повторной отправки во время асинхронных запросов.
     * @memberof TaskCreationModal
     * @instance
     * @param {boolean} loading - Если true, кнопка блокируется и текст меняется на "Создание...";
     *                            если false, кнопка разблокируется и текст становится "Продолжить".
     * @returns {void}
     * @example
     * // Блокировка кнопки во время AJAX-запроса
     * TaskCreationModal.setSubmitState(true);
     * wp.ajax.post(...).always(() => TaskCreationModal.setSubmitState(false));
     */
    setSubmitState(loading) {
        const $submitBtn = $('#fs-modal-submit');
        $submitBtn
            .prop('disabled', loading)
            .text(loading ? 'Создание...' : 'Продолжить');
    },

    /**
     * Приватный метод: собирает и возвращает данные из формы создания задачи.
     * @memberof TaskCreationModal
     * @instance
     * @private
     * @returns {TaskFormData} Объект с данными формы.
     * @property {string} termId - ID выбранного термина (категории).
     * @property {string} title - Заголовок задачи.
     * @property {string} boilerplateUid - UID выбранного шаблона.
     */
    _getFormData() {
        return {
            termId: $('#fs-modal-term').val(),           // Значение select (обычно ID термина)
            title: $('#fs-modal-title').val(),           // Текст из поля ввода заголовка
            boilerplateUid: $('#fs-modal-boilerplate').val(), // UID шаблона
        };
    },
};

/**
 * @typedef {Object} TaskFormData
 * @property {string} termId - ID выбранного термина таксономии.
 * @property {string} title - Заголовок создаваемой задачи.
 * @property {string} boilerplateUid - Уникальный идентификатор выбранного шаблона.
 */

/**
 * Функция-обёртка для безопасной инициализации модуля в WordPress.
 * @function initTaskCreationModal
 * @description Проверяет наличие необходимых глобальных данных (fs_lms_task_data) и инициализирует модальное окно.
 * @returns {void}
 * @example
 * // В основном файле скрипта плагина
 * jQuery(document).ready(() => {
 *     initTaskCreationModal();
 * });
 */
export function initTaskCreationModal() {
    // Проверяем, что jQuery загружена
    if (typeof jQuery === 'undefined') {
        console.error('[TaskCreationModal] jQuery не загружена. Плагин не будет работать.');
        return;
    }

    // Проверяем, что глобальные данные от WordPress переданы через wp_localize_script
    if (typeof fs_lms_task_data === 'undefined') {
        console.error('[TaskCreationModal] Глобальные данные fs_lms_task_data не найдены. ' +
            'Убедитесь, что wp_localize_script() вызван для этого скрипта.');
        return;
    }

    TaskCreationModal.init();
}