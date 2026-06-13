/**
 * @module TaskModal
 * @description UI-компонент модального окна создания новой задачи (задания).
 *              Отвечает за:
 *              - Перехват стандартной кнопки WordPress "Добавить новую" (.page-title-action)
 *                и открытие модального окна вместо перехода на стандартную страницу создания.
 *              - Управление жизненным циклом модалки (открытие, закрытие, сброс формы).
 *              - Динамическое обновление выпадающих списков (типы задач и шаблоны).
 *              - Сбор данных формы и уведомление внешнего менеджера через систему колбэков.
 *              - Блокировку кнопки отправки во время AJAX-запроса.
 *
 *              В отличие от других модалок, этот компонент активно использует паттерн
 *              колбэков (onOpen, onTermChange, onSubmit) для делегирования бизнес-логики
 *              (загрузка данных, валидация, отправка) внешнему менеджеру (TaskModalManager).
 *
 * @requires jQuery
 * @requires openModal, closeModal, bindEsc, unbindEsc - базовые утилиты управления модальными окнами
 */

import '../_types.js';
import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

/**
 * UI-компонент модального окна создания задачи.
 * Работает в тесной связке с TaskModalManager, который подписывается на события
 * этого компонента и выполняет AJAX-запросы.
 */
export const TaskModal = {
    /** @type {boolean} Флаг для предотвращения повторной инициализации */
    _initialized: false,

    /**
     * Объект для хранения колбэков, переданных извне (из TaskModalManager).
     * Это реализация простого паттерна Издатель-Подписчик (Pub/Sub).
     * @private
     * @type {Object}
     * @property {Function|null} onOpen - Вызывается при открытии модалки.
     * @property {Function|null} onTermChange - Вызывается при смене типа задачи.
     * @property {Function|null} onSubmit - Вызывается при попытке отправки формы.
     */
    _callbacks: { onOpen: null, onTermChange: null, onSubmit: null },

    /** @type {jQuery|null} Ссылка на основной контейнер модального окна */
    $modal: null,
    /** @type {jQuery|null} Ссылка на форму создания задачи */
    $form: null,
    /** @type {jQuery|null} Выпадающий список типов задач (terms) */
    $termSelect: null,
    /** @type {jQuery|null} Выпадающий список шаблонов задач (boilerplates) */
    $boilerplateSelect: null,
    /** @type {jQuery|null} Кнопка отправки формы */
    $submitBtn: null,
    /** @type {jQuery|null} Поле ввода названия задачи */
    $titleInput: null,

    /**
     * Инициализация компонента.
     * Кэширует DOM-элементы и проверяет наличие модалки на странице.
     */
    init() {
        this.$modal = $('#fs-task-modal');
        this.$form  = $('#fs-task-creation-form');

        // Ранний выход, если модалки нет в DOM или она уже была инициализирована.
        // Это предотвращает ошибки и дублирование обработчиков событий.
        if (!this.$modal.length || this._initialized) {
            return;
        }

        this._initialized = true;

        // Кэширование элементов формы для оптимизации производительности.
        // Поиск через $('#id') выполняется один раз, последующие обращения идут к кэшированным объектам.
        this.$termSelect        = $('#fs-modal-term');
        this.$boilerplateSelect = $('#fs-modal-boilerplate');
        this.$submitBtn         = $('#fs-modal-submit');
        this.$titleInput        = $('#fs-modal-title');

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * Использует неймспейсинг '.fs' для безопасного удаления обработчиков при необходимости.
     * @private
     */
    _bindEvents() {
        // ПАТТЕРН: Перехват стандартной кнопки WordPress "Добавить новую".
        // Вместо того чтобы удалять кнопку из DOM, мы перехватываем клик по ней.
        // Проверяем, ведет ли ссылка на создание нового поста нужного типа (post_type).
        // Если да, отменяем стандартный переход (e.preventDefault()) и открываем нашу модалку.
        // Это сохраняет нативный UX WordPress, но подменяет его поведение на SPA-подобное.
        $('body')
            .off('click.fs', '.page-title-action') // Удаляем старые обработчики во избежание дублирования
            .on('click.fs', '.page-title-action', (e) => {
                const href = $(e.currentTarget).attr('href') || '';
                const postType = fs_lms_task_data?.post_type || '';

                if (href.includes('post-new.php') && href.includes('post_type=' + postType)) {
                    e.preventDefault();
                    this.open();
                }
            });

        // Закрытие модалки при клике на фон, крестик или кнопку отмены
        this.$modal.on('click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .fs-lms-modal-close, .js-modal-close', (e) => {
            e.preventDefault();
            this.close();
        });

        // Обработчик изменения типа задачи (первый dropdown).
        // При смене значения извлекаем data-slug выбранной опции и передаем его во внешний колбэк.
        // Менеджер использует этот slug для загрузки соответствующих шаблонов (boilerplates).
        this.$termSelect.off('change.fs').on('change.fs', () => {
            const termSlug = this.$termSelect.find('option:selected').data('slug');
            if (typeof this._callbacks.onTermChange === 'function') {
                this._callbacks.onTermChange(termSlug);
            }
        });

        // Перехват отправки формы.
        // Отменяем стандартную отправку, собираем данные и передаем их во внешний колбэк.
        // Вся валидация и AJAX-логика делегируется менеджеру.
        this.$form.off('submit.fs').on('submit.fs', (e) => {
            e.preventDefault();
            if (typeof this._callbacks.onSubmit === 'function') {
                this._callbacks.onSubmit(this._getFormData());
            }
        });
    },

    /**
     * Регистрация колбэка, вызываемого при открытии модалки.
     * @param {Function} fn - Функция-обработчик.
     */
    onOpen(fn)       { this._callbacks.onOpen = fn; },

    /**
     * Регистрация колбэка, вызываемого при смене типа задачи.
     * @param {Function} fn - Функция-обработчик, принимающая slug типа задачи.
     */
    onTermChange(fn) { this._callbacks.onTermChange = fn; },

    /**
     * Регистрация колбэка, вызываемого при попытке отправки формы.
     * @param {Function} fn - Функция-обработчик, принимающая объект с данными формы.
     */
    onSubmit(fn)     { this._callbacks.onSubmit = fn; },

    /**
     * Открытие модального окна.
     * Сбрасывает форму и уведомляет менеджер о том, что модалка открыта
     * (менеджер может использовать это для загрузки начальных данных, например, списка типов задач).
     */
    open() {
        openModal(this.$modal);
        bindEsc('task_creation', () => this.close());

        // Сброс формы к исходному состоянию при каждом открытии.
        // Проверка this.$form[0] гарантирует, что мы работаем с нативным HTML-элементом формы.
        if (this.$form && this.$form[0]) {
            this.$form[0].reset();
        }

        // Уведомляем менеджер об открытии
        if (typeof this._callbacks.onOpen === 'function') {
            this._callbacks.onOpen();
        }
    },

    /**
     * Закрытие модального окна и отвязка глобальных обработчиков.
     */
    close() {
        closeModal(this.$modal);
        unbindEsc('task_creation');
    },

    /**
     * Динамическое обновление содержимого выпадающего списка типов задач.
     * Вызывается менеджером после успешной загрузки данных с сервера.
     *
     * @param {string} html - HTML-разметка с тегами <option> для вставки в select.
     */
    setTerms(html) {
        if (!this.$termSelect.length) return;
        // .html() заменяет всё содержимое select.
        // .prop('disabled', false) гарантирует, что список доступен для выбора 
        // (он может быть заблокирован во время загрузки данных).
        this.$termSelect.html(html).prop('disabled', false);
    },

    /**
     * Динамическое обновление содержимого выпадающего списка шаблонов задач.
     * Вызывается менеджером после успешной загрузки шаблонов для выбранного типа задачи.
     *
     * @param {string} html - HTML-разметка с тегами <option> для вставки в select.
     */
    setBoilerplates(html) {
        if (!this.$boilerplateSelect.length) return;
        this.$boilerplateSelect.html(html).prop('disabled', false);
    },

    /**
     * Управление состоянием кнопки отправки (блокировка и изменение текста).
     * Вызывается менеджером при начале и завершении AJAX-запроса для защиты от двойного клика.
     *
     * @param {boolean} loading - Флаг состояния загрузки.
     */
    setSubmitState(loading) {
        if (!this.$submitBtn.length) return;
        this.$submitBtn
            .prop('disabled', loading)
            .text(loading ? 'Создание...' : 'Продолжить');
    },

    /**
     * Сбор данных из полей формы в единый объект.
     * @private
     * @returns {Object} Объект с данными формы для отправки на сервер.
     */
    _getFormData() {
        return {
            termId:         this.$termSelect.val(),
            title:          this.$titleInput.val(),
            boilerplateUid: this.$boilerplateSelect.val(),
        };
    },
};