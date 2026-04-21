/**
 * @fileoverview Модуль создания задач (Task Creation) для плагина FS-LMS.
 * @description Обеспечивает интеграцию с модальным окном создания задач, загружает
 *              типы задач и шаблоны (boilerplates) через AJAX, обрабатывает отправку формы
 *              и создание новой задачи с последующим открытием в новой вкладке.
 * @requires jQuery - глобальная зависимость WordPress.
 * @requires ../_types.js - глобальные типы данных.
 * @requires ../components/task-creation-modal.js - Модальное окно создания задач.
 */

import '../_types.js';
import { TaskCreationModal } from '../components/task-creation-modal.js';

const $ = jQuery;

/**
 * Объект для управления процессом создания задач.
 * @namespace TaskCreation
 * @typedef {Object} TaskCreation
 * @property {boolean} _submitting - Флаг, предотвращающий повторную отправку формы.
 */
export const TaskCreation = {
    /**
     * Флаг состояния отправки формы.
     * Используется для блокировки повторных отправок во время выполнения AJAX-запроса.
     * @memberof TaskCreation
     * @instance
     * @private
     * @type {boolean}
     */
    _submitting: false,

    /**
     * Инициализирует модуль создания задач.
     * Настраивает колбэки для модального окна: открытие, изменение термина и отправка формы.
     * @memberof TaskCreation
     * @instance
     * @returns {void}
     * @example
     * // Инициализация после загрузки DOM
     * jQuery(document).ready(() => {
     *     TaskCreation.init();
     * });
     */
    init() {
        // Инициализируем модальное окно
        TaskCreationModal.init();

        /**
         * Колбэк при открытии модального окна: загружаем типы задач.
         */
        TaskCreationModal.onOpen(() => this.loadTaskTypes());

        /**
         * Колбэк при изменении выбранного термина: загружаем шаблоны для выбранного термина.
         * @param {string} slug - Слаг выбранного термина
         */
        TaskCreationModal.onTermChange((slug) => this.loadBoilerplates(slug));

        /**
         * Колбэк при отправке формы: создаём задачу с переданными данными.
         * @param {TaskFormData} data - Данные формы создания задачи
         */
        TaskCreationModal.onSubmit((data) => this.createTask(data));
    },

    /**
     * Загружает типы задач (номера) через AJAX и заполняет выпадающий список.
     * @memberof TaskCreation
     * @instance
     * @returns {void}
     * @fires jQuery.get - AJAX-запрос на получение типов задач
     */
    loadTaskTypes() {
        // Устанавливаем временное состояние загрузки в выпадающем списке терминов
        TaskCreationModal.setTerms('<option value="">Загрузка...</option>');

        /**
         * Выполняем AJAX-запрос на получение типов задач.
         * @param {string} url - URL обработчика AJAX WordPress
         * @param {Object} data - Параметры запроса
         * @param {string} data.action - AJAX-действие для получения типов задач
         * @param {string} data.subject_key - Ключ предмета
         * @param {string} data.nonce - Nonce для проверки безопасности
         */
        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskTypes,
            subject_key: fs_lms_task_data.subject_key,
            security:    fs_lms_task_data.security,
        }).done((res) => {
            /**
             * Формируем HTML для выпадающего списка.
             * @type {string}
             */
            let html = '<option value="">-- Выберите номер --</option>';

            /**
             * Если запрос успешен и данные получены, добавляем опции для каждого типа задачи.
             */
            if (res.success) {
                res.data.forEach(type => {
                    /**
                     * Добавляем опцию с value = id и data-slug = slug для использования при изменении термина.
                     */
                    html += `<option value="${type.id}" data-slug="${type.slug}">${type.description}</option>`;
                });
            }

            // Обновляем выпадающий список терминов
            TaskCreationModal.setTerms(html);
        });
    },

    /**
     * Загружает шаблоны (boilerplates) для выбранного термина через AJAX.
     * @memberof TaskCreation
     * @instance
     * @param {string} termSlug - Слаг выбранного термина (номера задачи).
     * @returns {void}
     * @fires jQuery.get - AJAX-запрос на получение шаблонов
     */
    loadBoilerplates(termSlug) {
        /**
         * Если термин не выбран, показываем сообщение и очищаем список шаблонов.
         */
        if (!termSlug) {
            TaskCreationModal.setBoilerplates('<option value="">-- Сначала выберите номер --</option>');
            return;
        }

        // Устанавливаем временное состояние загрузки
        TaskCreationModal.setBoilerplates('<option value="">Загрузка...</option>');

        /**
         * Выполняем AJAX-запрос на получение шаблонов для выбранного термина.
         * @param {string} url - URL обработчика AJAX WordPress
         * @param {Object} data - Параметры запроса
         * @param {string} data.action - AJAX-действие для получения шаблонов задач
         * @param {string} data.subject_key - Ключ предмета
         * @param {string} data.term_slug - Слаг выбранного термина
         * @param {string} data.nonce - Nonce для проверки безопасности
         */
        $.get(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.getTaskBoilerplates,
            subject_key: fs_lms_task_data.subject_key,
            term_slug:   termSlug,
            security:    fs_lms_task_data.security,
        }).done((res) => {
            /**
             * Формируем HTML для выпадающего списка шаблонов.
             * По умолчанию предлагаем вариант "Без шаблона".
             * @type {string}
             */
            let html = '<option value="">-- Без шаблона --</option>';

            /**
             * Если запрос успешен и данные являются массивом, добавляем опции для каждого шаблона.
             */
            if (res.success && Array.isArray(res.data)) {
                res.data.forEach(bp => {
                    /**
                     * Добавляем опцию с value = uid и текстом = title шаблона.
                     */
                    html += `<option value="${bp.uid}">${bp.title}</option>`;
                });
            }

            // Обновляем выпадающий список шаблонов
            TaskCreationModal.setBoilerplates(html);
        });
    },

    /**
     * Создаёт новую задачу через AJAX-запрос.
     * Выполняет валидацию обязательных полей, блокирует повторную отправку,
     * открывает созданную задачу в новой вкладке при успехе.
     * @memberof TaskCreation
     * @instance
     * @param {TaskFormData} data - Данные формы создания задачи.
     * @param {string} data.termId - ID выбранного термина (номера задачи).
     * @param {string} data.title - Заголовок задачи.
     * @param {string} data.boilerplateUid - UID выбранного шаблона (может быть пустым).
     * @returns {void}
     * @fires jQuery.post - AJAX-запрос на создание задачи
     */
    createTask(data) {
        /**
         * Проверяем, не выполняется ли уже отправка формы.
         * Если да — игнорируем повторный вызов.
         */
        if (this._submitting) return;

        /**
         * Получаем ключ предмета из глобальной переменной.
         * @type {string}
         */
        const subject_key = typeof fs_lms_task_data !== 'undefined' ? fs_lms_task_data.subject_key : '';

        /**
         * Валидация обязательных полей:
         * - termId (выбранный номер задачи)
         * - subject_key (ключ предмета)
         * - title (заголовок задачи)
         */
        if (!data.termId || !subject_key || !data.title) {
            alert('Пожалуйста, заполните все обязательные поля (Номер, Предмет, Заголовок).');
            return;
        }

        // Устанавливаем флаг отправки и блокируем кнопку отправки
        this._submitting = true;
        TaskCreationModal.setSubmitState(true);

        /**
         * Выполняем AJAX-запрос на создание задачи.
         * @param {string} url - URL обработчика AJAX WordPress
         * @param {Object} requestData - Данные для отправки
         * @param {string} requestData.action - AJAX-действие для создания задачи
         * @param {string} requestData.nonce - Nonce для проверки безопасности
         * @param {string} requestData.subject_key - Ключ предмета
         * @param {string} requestData.term_id - ID выбранного термина
         * @param {string} requestData.boilerplate_uid - UID выбранного шаблона
         * @param {string} requestData.title - Заголовок задачи
         */
        $.post(fs_lms_vars.ajaxurl, {
            action:          fs_lms_vars.ajax_actions.createTask,
            security:        fs_lms_task_data.security,
            subject_key:     subject_key,
            term_id:         data.termId,
            boilerplate_uid: data.boilerplateUid,
            title:           data.title,
        })
            .done((res) => {
                /**
                 * Обработка успешного ответа сервера.
                 * @param {Object} res - Ответ сервера
                 * @param {boolean} res.success - Флаг успешности операции
                 * @param {Object} res.data - Данные ответа
                 * @param {string} res.data.redirect - URL для открытия созданной задачи
                 */
                if (res.success) {
                    /**
                     * Открываем созданную задачу в новой вкладке браузера.
                     */
                    window.open(res.data.redirect, '_blank');

                    /**
                     * Закрываем модальное окно и восстанавливаем состояние кнопки.
                     */
                    TaskCreationModal.close();
                    TaskCreationModal.setSubmitState(false);
                } else {
                    /**
                     * При ошибке, возвращённой сервером, показываем сообщение.
                     * @type {string}
                     */
                    alert(res.data);
                    TaskCreationModal.setSubmitState(false);
                }
            })
            .fail(() => {
                /**
                 * Обработка ошибки HTTP-запроса (сервер недоступен, таймаут и т.д.).
                 */
                alert('Ошибка сервера при создании задания');
                TaskCreationModal.setSubmitState(false);
            })
            .always(() => {
                /**
                 * Восстанавливаем флаг отправки независимо от результата запроса.
                 */
                this._submitting = false;
            });
    },
};

